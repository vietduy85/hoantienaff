#Requires -Version 5.1
<#
    HoanTienAff - Automatic database backup (PowerShell 5.1)
    -------------------------------------------------------------------
    - Backup rieng tung database nghiep vu (hoantienaff, hoantien_creditcard)
      => file dump KHONG chua CREATE DATABASE / USE (an toan khi restore,
         khong the vo tinh ghi de production).
    - --single-transaction (anh chup nhat quan cho InnoDB, khong khoa ghi).
    - --result-file => ghi byte thuan UTF-8 (KHONG bi PowerShell doi sang UTF-16).
    - --net-buffer-length nho => moi cau INSERT < max_allowed_packet mac dinh (1MB).
    - SHA-256 + manifest + log; KHONG ghi de / KHONG xoa backup cu.
    - Neu bat ky database nao that bai => toan bo lan chay = FAILED (exit code 2).
#>
[CmdletBinding()]
param(
    [switch]$SkipManualCopy,
    [switch]$EnableRetention
)

$ErrorActionPreference = 'Stop'

# ----------------------------- Config -----------------------------
$MysqlBin        = 'C:\xampp\mysql\bin'
$MysqldumpExe    = Join-Path $MysqlBin 'mysqldump.exe'
$MysqlExe        = Join-Path $MysqlBin 'mysql.exe'
$DefaultsFile    = Join-Path $PSScriptRoot 'backup-my.cnf'

$BackupRoot      = 'D:\hoantienaff\backup'
$DailyDir        = Join-Path $BackupRoot 'daily'
$ManualDir       = Join-Path $BackupRoot 'manual_copy'
$LogsDir         = Join-Path $BackupRoot 'logs'
$ManifestDir     = Join-Path $BackupRoot 'manifests'
$GuideFile       = Join-Path $BackupRoot 'HUONG_DAN_COPY_BACKUP_RA_O_CUNG_NGOAI.txt'

$Databases       = @('hoantienaff', 'hoantien_creditcard')
$NetBufferLength = 32768

# Retention: MAC DINH TAT (an toan). Chi ap dung khi co -EnableRetention
# va ban backup moi da duoc xac minh day du.
$RetainDaily     = 14
$RetainWeekly    = 8
$RetainMonthly   = 6

# ----------------------------- Setup ------------------------------
$StartedAt  = Get-Date
$ts         = $StartedAt.ToString('yyyyMMdd_HHmmss')
$dateStamp  = $StartedAt.ToString('yyyyMMdd')
$setDir     = Join-Path $DailyDir $ts
$logFile    = Join-Path $LogsDir ("backup_{0}.log" -f $ts)
$utf8NoBom  = New-Object System.Text.UTF8Encoding($false)
$exitCode   = 0

# Shared helpers: OneDrive copy + backup status JSON
. (Join-Path $PSScriptRoot 'backup-onedrive-common.ps1')

function Write-Log {
    param([string]$Message, [ValidateSet('INFO','WARN','ERROR','OK')][string]$Level = 'INFO')
    $line = "{0} [{1}] {2}" -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Write-Host $line
    [System.IO.File]::AppendAllText($logFile, $line + "`r`n", $script:utf8NoBom)
}

function Run-MysqlScalar {
    param([string]$Sql)
    $e = [System.IO.Path]::GetTempFileName()
    try {
        $out = & $MysqlExe @("--defaults-extra-file=$DefaultsFile", "-N", "-B", "-e", $Sql) 2> $e
        return @($out | Where-Object { $_ -ne $null -and "$_" -ne '' })
    } finally {
        Remove-Item -LiteralPath $e -Force -ErrorAction SilentlyContinue
    }
}

function Get-UtfEncoding {
    param([string]$Path)
    $fs = [System.IO.File]::OpenRead($Path)
    try {
        $buf = New-Object byte[] 4
        $n = $fs.Read($buf, 0, 4)
    } finally { $fs.Close() }
    if ($n -ge 2) {
        if ($buf[0] -eq 0xFF -and $buf[1] -eq 0xFE) { return 'UTF-16LE' }
        if ($buf[0] -eq 0xFE -and $buf[1] -eq 0xFF) { return 'UTF-16BE' }
    }
    if ($n -ge 3 -and $buf[0] -eq 0xEF -and $buf[1] -eq 0xBB -and $buf[2] -eq 0xBF) { return 'UTF-8-BOM' }
    try {
        $enc = New-Object System.Text.UTF8Encoding($false, $true)
        [void]$enc.GetString([System.IO.File]::ReadAllBytes($Path))
        return 'UTF-8'
    } catch { return 'UNKNOWN/ANSI' }
}

function Get-DbTables {
    param([string]$Db)
    return (Run-MysqlScalar "SELECT table_name FROM information_schema.tables WHERE table_schema='$Db' AND table_type='BASE TABLE' ORDER BY table_name;")
}

function Get-TableCountsMap {
    param([string]$Db, [string[]]$Tables)
    $map = [ordered]@{}
    if (-not $Tables -or $Tables.Count -eq 0) { return $map }
    $parts = foreach ($t in $Tables) { "SELECT '$t' AS tbl, COUNT(*) AS c FROM $Db.$t" }
    $sql = ($parts -join " UNION ALL ") + ";"
    foreach ($line in (Run-MysqlScalar $sql)) {
        $cols = $line -split "`t"
        if ($cols.Count -ge 2) { $map[$cols[0]] = [int64]$cols[1] }
    }
    return $map
}

function Get-ObjectCounts {
    param([string]$Db)
    $sql = "SELECT 'triggers',COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$Db' UNION ALL " +
           "SELECT 'routines',COUNT(*) FROM information_schema.routines WHERE routine_schema='$Db' UNION ALL " +
           "SELECT 'events',COUNT(*) FROM information_schema.events WHERE event_schema='$Db' UNION ALL " +
           "SELECT 'views',COUNT(*) FROM information_schema.views WHERE table_schema='$Db';"
    $r = @{ triggers = 0; routines = 0; events = 0; views = 0 }
    foreach ($line in (Run-MysqlScalar $sql)) {
        $c = $line -split "`t"
        if ($c.Count -ge 2) { $r[$c[0]] = [int]$c[1] }
    }
    return $r
}

function Invoke-Dump {
    param([string]$Db, [string]$OutFile)
    $o = [System.IO.Path]::GetTempFileName()
    $e = [System.IO.Path]::GetTempFileName()
    $argList = @(
        "--defaults-extra-file=$DefaultsFile",
        "--single-transaction", "--quick",
        "--routines", "--events", "--triggers", "--hex-blob",
        "--no-tablespaces",
        "--default-character-set=utf8mb4",
        "--net-buffer-length=$NetBufferLength",
        "--result-file=$OutFile",
        $Db
    )
    $err = ''
    & $MysqldumpExe @argList 2> $e | Out-Null
    $code = $LASTEXITCODE
    if (Test-Path -LiteralPath $e) { $err = (Get-Content -LiteralPath $e -Raw) }
    Remove-Item -LiteralPath $o, $e -Force -ErrorAction SilentlyContinue
    return @{ Code = $code; StdErr = $err }
}

# ----------------------------- Main -------------------------------
New-Item -ItemType Directory -Path $setDir -Force | Out-Null
New-Item -ItemType Directory -Path $LogsDir, $ManifestDir -Force | Out-Null

Write-Log "================ BACKUP START  ts=$ts ================"
try {
    $dumpVersion = (& $MysqldumpExe --version 2>&1) -join ' '
    Write-Log "User=$env:USERNAME  Computer=$env:COMPUTERNAME"
    Write-Log "Tool: $dumpVersion"
    Write-Log "Databases: $($Databases -join ', ')"
} catch { Write-Log "Khong lay duoc version mysqldump: $_" 'WARN' }

$manifest = New-Object System.Text.StringBuilder
[void]$manifest.AppendLine("HoanTienAff Database Backup Manifest")
[void]$manifest.AppendLine("Run timestamp : " + $StartedAt.ToString('yyyy-MM-dd HH:mm:ss') + " (local)")
[void]$manifest.AppendLine("Host          : 127.0.0.1:3306")
[void]$manifest.AppendLine("Tool          : " + ($dumpVersion))
[void]$manifest.AppendLine("Method        : per-database dump; --single-transaction --quick --routines --events --triggers --hex-blob --no-tablespaces --default-character-set=utf8mb4 --net-buffer-length=$NetBufferLength --result-file")
[void]$manifest.AppendLine("Databases     : " + ($Databases -join ', '))
[void]$manifest.AppendLine("")

$allOk = $true
$dbResults = @()
$shaLines = @()

foreach ($db in $Databases) {
    Write-Log "---- Database: $db ----"
    $outFile = Join-Path $setDir ("{0}_{1}.sql" -f $db, $ts)
    $res = [ordered]@{
        Database = $db; File = (Split-Path $outFile -Leaf); Status = 'FAIL'
        SizeBytes = 0; Encoding = ''; Sha256 = ''; Tables = 0; DumpEnded = $false
        Objects = $null; Counts = $null; Error = ''
    }

    $expectedTables = @(Get-DbTables $db)
    $dump = Invoke-Dump $db $outFile

    if ($dump.Code -ne 0) {
        $res.Error = "mysqldump exit=$($dump.Code): $($dump.StdErr)"
        Write-Log "FAIL dump $db -> $($res.Error)" 'ERROR'
        $allOk = $false
    }
    elseif (-not (Test-Path -LiteralPath $outFile) -or (Get-Item -LiteralPath $outFile).Length -eq 0) {
        $res.Error = "File dump trong hoac khong ton tai"
        Write-Log "FAIL $db -> $($res.Error)" 'ERROR'
        $allOk = $false
    }
    else {
        $res.SizeBytes = (Get-Item -LiteralPath $outFile).Length
        $res.Encoding  = Get-UtfEncoding $outFile
        $res.Sha256    = (Get-FileHash -LiteralPath $outFile -Algorithm SHA256).Hash
        $res.Tables    = (Select-String -LiteralPath $outFile -Pattern '^CREATE TABLE' -AllMatches | Measure-Object).Count
        $tail          = (Get-Content -LiteralPath $outFile -Tail 5) -join "`n"
        $res.DumpEnded = ($tail -match 'Dump completed')
        $res.Objects   = Get-ObjectCounts $db
        $res.Counts    = Get-TableCountsMap $db $expectedTables

        $problems = @()
        if ($res.Encoding -notmatch '^UTF-8') { $problems += "Encoding khong phai UTF-8 ($($res.Encoding))" }
        if (-not $res.DumpEnded)               { $problems += "Thieu '-- Dump completed'" }
        if ($res.Tables -ne $expectedTables.Count) { $problems += "So bang trong dump ($($res.Tables)) != information_schema ($($expectedTables.Count))" }
        if ((Select-String -LiteralPath $outFile -Pattern '^(USE |CREATE DATABASE)' -Quiet)) { $problems += "Dump chua USE/CREATE DATABASE (bat thuong voi single-db)" }

        if ($problems.Count -gt 0) {
            $res.Error = ($problems -join '; ')
            Write-Log "FAIL xac minh $db -> $($res.Error)" 'ERROR'
            $allOk = $false
        } else {
            $res.Status = 'OK'
            Write-Log "OK $db  tables=$($res.Tables)  size=$($res.SizeBytes)  enc=$($res.Encoding)  sha256=$($res.Sha256)" 'OK'
        }
        $shaLines += ("{0}  {1}" -f $res.Sha256, $res.File)
    }

    $dbResults += [pscustomobject]$res

    [void]$manifest.AppendLine("Database: $db")
    [void]$manifest.AppendLine("  File      : " + $res.File)
    [void]$manifest.AppendLine("  Status    : " + $res.Status)
    [void]$manifest.AppendLine("  SizeBytes : " + $res.SizeBytes)
    [void]$manifest.AppendLine("  Encoding  : " + $res.Encoding)
    [void]$manifest.AppendLine("  SHA256    : " + $res.Sha256)
    [void]$manifest.AppendLine("  Tables    : " + $res.Tables + " (information_schema=" + $expectedTables.Count + ")")
    [void]$manifest.AppendLine("  DumpEnded : " + $res.DumpEnded)
    if ($res.Objects) {
        [void]$manifest.AppendLine("  Objects   : triggers=$($res.Objects.triggers) routines=$($res.Objects.routines) events=$($res.Objects.events) views=$($res.Objects.views)")
    }
    if ($res.Counts) {
        [void]$manifest.AppendLine("  RowCounts :")
        foreach ($k in $res.Counts.Keys) { [void]$manifest.AppendLine("    $k=$($res.Counts[$k])") }
    }
    if ($res.Error) { [void]$manifest.AppendLine("  Error     : " + $res.Error) }
    [void]$manifest.AppendLine("")
}

$overall = if ($allOk) { 'SUCCESS' } else { 'FAILED' }
[void]$manifest.Insert(0, ("Overall status: " + $overall + "`r`n"))

Write-Log ("OVERALL: " + $overall) ($(if ($allOk) {'OK'} else {'ERROR'}))

# ---------------- Write manifest + sha256 ----------------
$manifestPath = Join-Path $setDir ("MANIFEST_{0}.txt" -f $ts)
[System.IO.File]::WriteAllText($manifestPath, $manifest.ToString(), $utf8NoBom)
[System.IO.File]::WriteAllText((Join-Path $setDir 'MANIFEST.sha256'), (($shaLines -join "`r`n") + "`r`n"), $utf8NoBom)
Copy-Item -LiteralPath $manifestPath -Destination (Join-Path $ManifestDir ("MANIFEST_{0}.txt" -f $ts)) -Force
Write-Log "Manifest: $manifestPath"

# Counts file rieng tung DB (de restore test so sanh so dong tung bang)
foreach ($r in $dbResults) {
    if ($r.Status -eq 'OK' -and $r.Counts) {
        $countLines = foreach ($k in $r.Counts.Keys) { "$k=$($r.Counts[$k])" }
        $countsPath = Join-Path $setDir ("counts_{0}_{1}.txt" -f $r.Database, $ts)
        [System.IO.File]::WriteAllText($countsPath, (($countLines -join "`r`n") + "`r`n"), $utf8NoBom)
    }
}

# ---------------- Refresh manual_copy (chi khi THANH CONG) ----------------
if ($allOk -and -not $SkipManualCopy) {
    if (Test-Path -LiteralPath $ManualDir) {
        Get-ChildItem -LiteralPath $ManualDir -File -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -match '^(hoantienaff_|hoantien_creditcard_|MANIFEST)' } |
            Remove-Item -Force -ErrorAction SilentlyContinue
    } else { New-Item -ItemType Directory -Path $ManualDir -Force | Out-Null }
    foreach ($r in $dbResults) {
        Copy-Item -LiteralPath (Join-Path $setDir $r.File) -Destination (Join-Path $ManualDir $r.File) -Force
    }
    Copy-Item -LiteralPath $manifestPath -Destination (Join-Path $ManualDir (Split-Path $manifestPath -Leaf)) -Force
    Copy-Item -LiteralPath (Join-Path $setDir 'MANIFEST.sha256') -Destination (Join-Path $ManualDir 'MANIFEST.sha256') -Force
    if (Test-Path -LiteralPath $GuideFile) { Copy-Item -LiteralPath $GuideFile -Destination (Join-Path $ManualDir (Split-Path $GuideFile -Leaf)) -Force }
    Write-Log "manual_copy da cap nhat bang bo backup moi nhat ($ts)."
}

# ---------------- Backup status JSON + OneDrive copy ----------------
$dbInfoArr = @()
foreach ($r in $dbResults) {
    $dbInfoArr += @{
        db = "$($r.Database)"; file = "$($r.File)"; size = $r.SizeBytes
        sha256 = "$($r.Sha256)"; encoding = "$($r.Encoding)"; tables = $r.Tables
        status = "$($r.Status)"
    }
}

$localStatus = $(if ($allOk) { 'SUCCESS' } else { 'FAILED' })
Write-BackupStatus @{
    last_backup = @{
        time = $StartedAt.ToString('o')
        set_id = $ts
        status = $localStatus
        databases = @($Databases)
        files = $dbInfoArr
        error = $(if ($allOk) { $null } else { "Backup database that bai - xem log: $(Split-Path $logFile -Leaf)" })
    }
}
Write-Log "Status file da cap nhat last_backup -> $localStatus"

if ($allOk) {
    $oneRes = Copy-BackupSetToOneDrive -SetId $ts
    if ($oneRes.status -eq 'SUCCESS') {
        Write-Log ("OneDrive: copy xong + da xac minh SHA-256 -> " + $oneRes.target_dir) 'OK'
    } else {
        Write-Log ("OneDrive: " + $oneRes.status + " - " + $oneRes.error) 'WARN'
    }
    $patch2 = New-OneDriveStatusPatch $oneRes
    $patch2 += New-CloudSyncPatch
    Write-BackupStatus $patch2
} else {
    Write-BackupStatus @{
        onedrive = @{
            time = $null; status = 'PENDING'; set_id = $ts
            target_dir = $script:ONEDRIVE_ROOT; files = @()
            error = 'Backup local that bai - chua copy sang OneDrive'
        }
        cloud_sync = @{ status = 'PENDING'; note = 'Cho thuc hien sao chep OneDrive khi backup local thanh cong.'; checked_at = (Get-Date).ToString('o') }
    }
}

# ---------------- Retention (mac dinh TAT) ----------------
if ($allOk -and $EnableRetention) {
    Write-Log "Retention BAT: giu daily=$RetainDaily weekly=$RetainWeekly monthly=$RetainMonthly (chua trien khai xoa tu dong trong ban nay)." 'WARN'
} elseif (-not $EnableRetention) {
    Write-Log "Retention TAT (giu tat ca). Dung -EnableRetention de bat sau khi danh gia an toan." 'INFO'
}

if (-not $allOk) { $exitCode = 2 }
Write-Log "================ BACKUP END  status=$overall exit=$exitCode ================"
exit $exitCode
