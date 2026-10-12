#Requires -Version 5.1
<#
    HoanTienAff - Restore TEST vao database TEST (KHONG BAO GIO import vao production)
    -------------------------------------------------------------------
    - Bat buoc ten DB dich phai chua chuoi "_restore_test_" (chan doan an toan).
    - Khong ghi de: neu DB dich da ton tai => dung ngay.
    - Mac dinh restore bang cau hinh packet HIEN TAI. Chi khi co -AllowRaisePacket
      moi tam nang max_allowed_packet (luu gia tri goc, khoi phuc trong finally).
    - Khong import vao DB nghiep vu. Khong dong .env, khong sua my.ini.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$SqlFile,
    [Parameter(Mandatory=$true)][string]$TargetDb,
    [string]$ExpectedCountsFile = '',
    [switch]$AllowRaisePacket,
    [string]$PacketSize = '64M'
)

$ErrorActionPreference = 'Stop'

$MysqlBin     = 'C:\xampp\mysql\bin'
$MysqlExe     = Join-Path $MysqlBin 'mysql.exe'
$DefaultsFile = Join-Path $PSScriptRoot 'backup-my.cnf'
$utf8NoBom    = New-Object System.Text.UTF8Encoding($false)

# ----------------------- Safety guards -----------------------
$ProductionDbs = @('hoantienaff', 'hoantien_creditcard')
if ($ProductionDbs -contains $TargetDb) { throw "TU CHOI: '$TargetDb' la database production." }
if ($TargetDb -notmatch '_restore_test_') { throw "TU CHOI: ten DB dich phai chua '_restore_test_' (nhan duoc '$TargetDb')." }
if (-not (Test-Path -LiteralPath $SqlFile)) { throw "Khong tim thay file dump: $SqlFile" }

function Invoke-MysqlFile {
    param([string]$Db, [string]$File, [string]$ErrFile, [string]$OutFile)
    $p = Start-Process -FilePath $MysqlExe `
        -ArgumentList @("--defaults-extra-file=$DefaultsFile", "--default-character-set=utf8mb4", $Db) `
        -NoNewWindow -Wait -PassThru -RedirectStandardInput $File `
        -RedirectStandardOutput $OutFile -RedirectStandardError $ErrFile
    return $p.ExitCode
}

function Run-Sql {
    param([string]$Sql)
    $e = [System.IO.Path]::GetTempFileName()
    try {
        $out = & $MysqlExe @("--defaults-extra-file=$DefaultsFile", "-N", "-B", "-e", $Sql) 2> $e
        return @($out | Where-Object { $_ -ne $null -and "$_" -ne '' })
    } finally { Remove-Item -LiteralPath $e -Force -ErrorAction SilentlyContinue }
}

# ----------------------- Pre-flight -----------------------
$existing = Run-Sql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$TargetDb';"
if ($existing) { throw "TU CHOI: database '$TargetDb' da ton tai. Khong ghi de (chon timestamp moi)." }

$origPacket = @(Run-Sql "SELECT @@global.max_allowed_packet;")[0]
Write-Host "[INFO] TargetDB    : $TargetDb"
Write-Host "[INFO] SqlFile     : $SqlFile"
Write-Host "[INFO] max_allowed_packet hien tai: $origPacket"

$created = $false
$raised  = $false
$ret = 1
try {
    [void](Run-Sql "CREATE DATABASE ``$TargetDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
    $created = $true
    Write-Host "[INFO] Da tao database test '$TargetDb'."

    $err = [System.IO.Path]::GetTempFileName()
    $out = [System.IO.Path]::GetTempFileName()
    $ret = Invoke-MysqlFile -Db $TargetDb -File $SqlFile -ErrFile $err -OutFile $out
    $errText = if (Test-Path $err) { (Get-Content -LiteralPath $err -Raw) } else { '' }

    if ($ret -ne 0 -and $errText -match 'max_allowed_packet' -and $AllowRaisePacket) {
        Write-Host "[WARN] Loi max_allowed_packet -> tam nang len $PacketSize va thu lai." -ForegroundColor Yellow
        [void](Run-Sql "SET GLOBAL max_allowed_packet=$PacketSize;")
        $raised = $true
        $ret = Invoke-MysqlFile -Db $TargetDb -File $SqlFile -ErrFile $err -OutFile $out
        $errText = if (Test-Path $err) { (Get-Content -LiteralPath $err -Raw) } else { '' }
    }

    Remove-Item -LiteralPath $err,$out -Force -ErrorAction SilentlyContinue
    if ($ret -ne 0) {
        Write-Host "[FAIL] Import exit=$ret" -ForegroundColor Red
        if ($errText) { Write-Host $errText }
        exit 3
    }
    Write-Host "[OK] Import thanh cong vao '$TargetDb'." -ForegroundColor Green

    # ---------------- Verification ----------------
    $tables = Run-Sql ("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TargetDb' AND table_type='BASE TABLE';")
    Write-Host "[INFO] So bang (BASE TABLE): $tables"
    $objs = Run-Sql "SELECT 'triggers',COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$TargetDb' UNION ALL SELECT 'routines',COUNT(*) FROM information_schema.routines WHERE routine_schema='$TargetDb' UNION ALL SELECT 'events',COUNT(*) FROM information_schema.events WHERE event_schema='$TargetDb' UNION ALL SELECT 'views',COUNT(*) FROM information_schema.views WHERE table_schema='$TargetDb';"
    $objs | ForEach-Object { Write-Host "[INFO] Object $_" }

    if ($ExpectedCountsFile -and (Test-Path -LiteralPath $ExpectedCountsFile)) {
        $mismatch = 0; $checked = 0
        foreach ($line in (Get-Content -LiteralPath $ExpectedCountsFile)) {
            if ($line -notmatch '=') { continue }
            $parts = $line -split '='
            $tbl = $parts[0].Trim(); $exp = [int64]$parts[1].Trim()
            if ($tbl -notmatch '^[A-Za-z0-9_]+$') { continue }
            $got = [int64](@(Run-Sql "SELECT COUNT(*) FROM $TargetDb.$tbl;")[0])
            $checked++
            if ($got -ne $exp) {
                $mismatch++
                Write-Host ("[MISMATCH] {0}: dump={1} restored={2}" -f $tbl,$exp,$got) -ForegroundColor Yellow
            }
        }
        Write-Host "[INFO] Da so sanh $checked bang; lech=$mismatch"
        if ($mismatch -gt 0) { Write-Host "[FAIL] Co bang lech so dong." -ForegroundColor Red; exit 4 }
    }
    Write-Host "[PASS] Restore test '$TargetDb' thanh cong." -ForegroundColor Green
    exit 0
}
finally {
    if ($raised) {
        [void](Run-Sql "SET GLOBAL max_allowed_packet=$origPacket;")
        $now = @(Run-Sql "SELECT @@global.max_allowed_packet;")[0]
        Write-Host "[INFO] Da khoi phuc max_allowed_packet = $now (goc $origPacket)."
    }
    if ($created) { Write-Host "[INFO] Database test '$TargetDb' duoc GIU LAI (khong tu dong xoa)." }
}
