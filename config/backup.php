<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Backup Status
    |--------------------------------------------------------------------------
    |
    | Absolute, server-local path to the status JSON written by the PowerShell
    | backup pipeline (scripts/database-backup/backup-database.ps1 and
    | sync-backup-to-onedrive.ps1). The web server (Apache / LocalSystem) must
    | be able to read this file. Only used for display on the order-sync page.
    |
    */

    'status_path' => env('BACKUP_STATUS_PATH', 'D:\\hoantienaff\\backup\\status\\backup-status.json'),

];
