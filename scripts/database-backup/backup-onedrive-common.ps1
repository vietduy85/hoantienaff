# =====================================================================
#  HoanTienAff - shared helpers: OneDrive copy + backup status JSON
#  Dot-source this file from backup-database.ps1 / sync-backup-to-onedrive.ps1
#  - Never deletes or overwrites a differing destination file.
#  - Uses a global mutex so backup-copy and retry-sync never run together.
#  - Writes a machine-readable status JSON consumed by the Laravel UI.
# =====================================================================

$script:BACKUP_ROOT    = 'D:\hoantienaff\backup'
$script:ONEDRIVE_ROOT  = 'D:\OneDrive\HoanTienAff-Database-Backup'
$script:STATUS_FILE    = Join-Path $script:BACKUP_ROOT 'status\backup-status.json'
$script:ONEDRIVE_MUTEX = 'Global\HoanTienAffBackupOneDrive'
$script:StatusUtf8     = New-Object System.Text.UTF8Encoding($false)

function Ensure-Dir {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { New-Item -ItemType Directory -Path $Path -Force | Out-Null }
}

function Read-BackupStatus {
    $obj = @{}
    if (Test-Path -LiteralPath $script:STATUS_FILE) {
        try {
            $prev = Get-Content -LiteralPath $script:STATUS_FILE -Raw -Encoding UTF8 | ConvertFrom-Json
            foreach ($p in $prev.PSObject.Properties) { $obj[$p.Name] = $p.Value }
        } catch { }
    }
    return $obj
}

function Write-BackupStatus {
    param([hashtable]$Patch)
    Ensure-Dir (Split-Path -Parent $script:STATUS_FILE)
    $obj = Read-BackupStatus
    foreach ($k in $Patch.Keys) { $obj[$k] = $Patch[$k] }
    $obj['schema_version'] = 1
    $obj['updated_at']     = (Get-Date).ToString('o')
    $json = $obj | ConvertTo-Json -Depth 8
    $tmp  = "$($script:STATUS_FILE).tmp"
    [System.IO.File]::WriteAllText($tmp, $json, $script:StatusUtf8)
    Move-Item -LiteralPath $tmp -Destination $script:STATUS_FILE -Force
}

function Get-ManifestOverallStatus {
    param([string]$ManifestPath)
    if (-not (Test-Path -LiteralPath $ManifestPath)) { return $null }
    $m = Select-String -LiteralPath $ManifestPath -Pattern '^Overall status:\s*(\w+)' | Select-Object -First 1
    if ($m) { return $m.Matches[0].Groups[1].Value }
    return $null
}

# Returns info about one backup set (completeness = manifest SUCCESS + both DB .sql present)
function Get-BackupSetInfo {
    param([string]$SetId)
    $setDir = Join-Path (Join-Path $script:BACKUP_ROOT 'daily') $SetId
    $out = [ordered]@{
        set_id = $SetId; dir = $setDir; exists = (Test-Path -LiteralPath $setDir)
        complete = $false; manifest = $null; manifest_status = $null
        files = @(); db_files = @{}
    }
    if (-not $out.exists) { return [pscustomobject]$out }

    $manifests = Get-ChildItem -LiteralPath $setDir -Filter 'MANIFEST_*.txt' -File -ErrorAction SilentlyContinue
    if ($manifests) {
        $out.manifest = ($manifests | Sort-Object Name | Select-Object -Last 1).FullName
        $out.manifest_status = Get-ManifestOverallStatus $out.manifest
    }
    $files = @(Get-ChildItem -LiteralPath $setDir -File -ErrorAction SilentlyContinue)
    $out.files = @($files | ForEach-Object { $_.Name })
    $dbfiles = @{}
    foreach ($s in ($files | Where-Object { $_.Name -match '^[A-Za-z0-9_]+_\d{8}_\d{6}\.sql$' })) {
        $db = ($s.Name -replace '_\d{8}_\d{6}\.sql$', '')
        $dbfiles[$db] = $s.Name
    }
    $out.db_files = $dbfiles
    $out.complete = ($out.manifest_status -eq 'SUCCESS') -and
                    $dbfiles.ContainsKey('hoantienaff') -and $dbfiles.ContainsKey('hoantien_creditcard')
    return [pscustomobject]$out
}

function Get-CompleteBackupSetIds {
    $daily = Join-Path $script:BACKUP_ROOT 'daily'
    if (-not (Test-Path -LiteralPath $daily)) { return @() }
    $complete = @()
    foreach ($d in (Get-ChildItem -LiteralPath $daily -Directory -ErrorAction SilentlyContinue | Sort-Object Name)) {
        if ((Get-BackupSetInfo $d.Name).complete) { $complete += $d.Name }
    }
    return $complete
}

# Copy one complete set into D:\OneDrive\...\daily\<setid>\ ; verify SHA-256 ; never overwrite differing files.
function Copy-BackupSetToOneDrive {
    param([string]$SetId)
    $now  = (Get-Date).ToString('o')
    $info = Get-BackupSetInfo $SetId

    if (-not $info.exists) {
        return [pscustomobject]@{ status='FAILED'; set_id=$SetId; target_dir=$null; files=@(); error="Khong tim thay set $SetId"; time=$now }
    }
    if (-not $info.complete) {
        return [pscustomobject]@{ status='PENDING'; set_id=$SetId; target_dir=$null; files=@(); error="Set chua hoan chinh (manifest khong SUCCESS hoac thieu .sql) - khong copy"; time=$now }
    }

    $mutex = [System.Threading.Mutex]::new($false, $script:ONEDRIVE_MUTEX)
    if (-not $mutex.WaitOne(0)) {
        return [pscustomobject]@{ status='PENDING'; set_id=$SetId; target_dir=$null; files=@(); error='Mot tien trinh copy OneDrive khac dang chay'; time=$now }
    }
    try {
        $targetDir = $null
        try {
            $targetDir = Join-Path (Join-Path $script:ONEDRIVE_ROOT 'daily') $SetId
            Ensure-Dir $targetDir

            $results = @()
            $error   = $null
            foreach ($f in (Get-ChildItem -LiteralPath $info.dir -File)) {
                $src = $f.FullName
                $dst = Join-Path $targetDir $f.Name

                # Ensure source is fully written (exclusive open must succeed).
                try {
                    $fs = [System.IO.File]::Open($src, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::None)
                    $fs.Close()
                } catch {
                    $error = "File nguon dang duoc ghi: $($f.Name)"
                    $results += [pscustomobject]@{ name=$f.Name; size=$f.Length; sha256_src=$null; sha256_dst=$null; verified=$false; action='source-locked' }
                    continue
                }

                $shaSrc = (Get-FileHash -LiteralPath $src -Algorithm SHA256).Hash
                if (Test-Path -LiteralPath $dst) {
                    $shaDst = (Get-FileHash -LiteralPath $dst -Algorithm SHA256).Hash
                    if ($shaDst -eq $shaSrc) {
                        $verified = $true; $action = 'already-present'
                    } else {
                        $verified = $false; $action = 'conflict'
                        $error = "Checksum khac o file dich (khong ghi de): $($f.Name)"
                    }
                } else {
                    Copy-Item -LiteralPath $src -Destination $dst
                    $shaDst   = (Get-FileHash -LiteralPath $dst -Algorithm SHA256).Hash
                    $verified = ($shaDst -eq $shaSrc)
                    $action   = 'copied'
                    if (-not $verified) { $error = "Checksum sau khi copy khong khop: $($f.Name)" }
                }

                $results += [pscustomobject]@{
                    name=$f.Name; size=$f.Length; sha256_src=$shaSrc; sha256_dst=$shaDst;
                    verified=$verified; action=$action
                }
            }

            $status = if ($error) { 'FAILED' } else { 'SUCCESS' }
            return [pscustomobject]@{ status=$status; set_id=$SetId; target_dir=$targetDir; files=$results; error=$error; time=$now }
        } catch {
            return [pscustomobject]@{ status='FAILED'; set_id=$SetId; target_dir=$targetDir; files=@(); error=("Exception khi copy OneDrive: " + $_.Exception.Message); time=$now }
        }
    } finally {
        $mutex.ReleaseMutex()
        $mutex.Dispose()
    }
}

function New-OneDriveStatusPatch {
    param($Result)
    $files = @()
    foreach ($f in @($Result.files)) {
        $files += @{
            name=$f.name; size=$f.size; sha256_src=$f.sha256_src; sha256_dst=$f.sha256_dst;
            verified=$f.verified; action=$f.action
        }
    }
    return @{
        onedrive = @{
            time=$Result.time; status=$Result.status; set_id=$Result.set_id;
            target_dir=$Result.target_dir; files=$files; error=$Result.error
        }
    }
}

function New-CloudSyncPatch {
    return @{
        cloud_sync = @{
            status  = 'UNKNOWN'
            note    = 'Da copy vao thu muc OneDrive - chua xac minh dong bo cloud (khong co API/co che xac minh dang tin cay).'
            checked_at = (Get-Date).ToString('o')
        }
    }
}
