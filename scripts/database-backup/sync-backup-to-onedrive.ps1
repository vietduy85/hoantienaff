#Requires -Version 5.1
<#
    HoanTienAff - sync completed backup sets to OneDrive (retry / manual)
    - Only copies COMPLETE sets (manifest SUCCESS + both DB .sql).
    - Idempotent: files already present with matching SHA-256 are skipped.
    - Never overwrites a destination file with a different checksum.
    - Uses a global mutex so it never overlaps the backup script's own copy.
    - Updates D:\hoantienaff\backup\status\backup-status.json (read by the UI).
    - Local backups on D: are never touched / never deleted.
#>
[CmdletBinding()]
param(
    [ValidateRange(1, 500)]
    [int]$MaxSets = 30
)

$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'backup-onedrive-common.ps1')

$ts      = (Get-Date).ToString('yyyyMMdd_HHmmss')
$logFile = Join-Path (Join-Path $script:BACKUP_ROOT 'logs') ("onedrive_sync_{0}.log" -f $ts)

function Write-Log {
    param([string]$Message, [ValidateSet('INFO','WARN','ERROR','OK')][string]$Level = 'INFO')
    $line = "{0} [{1}] {2}" -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Write-Host $line
    [System.IO.File]::AppendAllText($logFile, $line + "`r`n", $script:StatusUtf8)
}

Write-Log "=== OneDrive SYN START ts=$ts ==="
Write-Log "Nguon: $script:BACKUP_ROOT\daily  ->  Dich: $script:ONEDRIVE_ROOT\daily"

$allIds = @(Get-CompleteBackupSetIds)   # ascending by set id
if ($allIds.Count -gt $MaxSets) { $allIds = $allIds[-$MaxSets..($allIds.Count - 1)] }

if ($allIds.Count -eq 0) {
    Write-Log "Chua co set backup hoan chinh nao de sync (khong co gi de copy)." 'WARN'
    Write-BackupStatus @{
        onedrive   = @{ time = $null; status = 'PENDING'; set_id = $null; target_dir = $script:ONEDRIVE_ROOT; files = @(); error = 'Chua co set backup hoan chinh de sync' }
        cloud_sync = @{ status = 'PENDING'; note = 'Chua co du lieu de xac minh.'; checked_at = (Get-Date).ToString('o') }
    }
    Write-Log "=== OneDrive SYN END status=PENDING exit=0 ==="
    exit 0
}

$latestRes = $null
foreach ($id in $allIds) {
    $r = Copy-BackupSetToOneDrive -SetId $id
    $latestRes = $r
    if ($r.status -eq 'SUCCESS') {
        Write-Log "OK set=$id -> $($r.target_dir)" 'OK'
        foreach ($f in @($r.files)) {
            Write-Log ("  - {0} action={1} verified={2} size={3}" -f $f.name, $f.action, $f.verified, $f.size)
        }
    } else {
        Write-Log ("WARN set=$id -> " + $r.status + " - " + $r.error) 'WARN'
    }
}

if ($latestRes) {
    $p = New-OneDriveStatusPatch $latestRes
    $p += New-CloudSyncPatch
    Write-BackupStatus $p
    $exit = if ($latestRes.status -eq 'SUCCESS') { 0 } else { 2 }
    Write-Log "=== OneDrive SYN END status=$($latestRes.status) exit=$exit ==="
    exit $exit
}

Write-Log "=== OneDrive SYN END status=UNKNOWN exit=2 ==="
exit 2