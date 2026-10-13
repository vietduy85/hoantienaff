<?php

namespace App\Services\Backup;

/**
 * Read-only view of the database backup status written by the PowerShell
 * backup pipeline.
 *
 * The file lives outside the web root (D:\hoantienaff\backup\status\). It must
 * not be modified here — the .ps1 scripts are the only writers. This service
 * only normalises the content for the UI. A missing, unreadable or malformed
 * file is reported as UNKNOWN ("Không đọc được trạng thái") — never guessed.
 *
 * @phpstan-type BackupFile array{db?: string, file?: string, size?: int|string, sha256?: string, status?: string}
 */
class BackupStatusService
{
    private const STATUS_LABELS = [
        'SUCCESS' => 'Thành công',
        'FAILED' => 'Thất bại',
        'PENDING' => 'Đang chờ',
        'UNKNOWN' => 'Chưa xác định',
    ];

    private const CLOUD_UNVERIFIED_NOTE = 'Đã copy vào thư mục OneDrive — chưa xác minh đồng bộ cloud (hệ thống không có API/cơ chế xác minh đáng tin cậy).';

    public function status(): array
    {
        $path = (string) config('backup.status_path');

        $raw = $this->read($path);

        if ($raw === null) {
            return $this->unreadable($path);
        }

        $lastBackup = $this->normalizeStatusBlock($raw['last_backup'] ?? null);
        $oneDrive = $this->normalizeStatusBlock($raw['onedrive'] ?? null);
        $cloudSync = $this->normalizeStatusBlock($raw['cloud_sync'] ?? null);

        $lastBackup['db_count'] = $this->databaseCount($raw['last_backup']['databases'] ?? null);
        $lastBackup['total_size'] = $this->totalSize($raw['last_backup']['files'] ?? null);
        $oneDrive['verified_count'] = $this->verifiedFileCount($raw['onedrive']['files'] ?? null);

        $cloudSync['note'] = $this->cloudSyncNote($cloudSync, $oneDrive, $raw['cloud_sync']['note'] ?? null);

        return [
            'readable' => true,
            'path' => $path,
            'updated_at' => isset($raw['updated_at']) ? (string) $raw['updated_at'] : null,
            'headline' => $this->headline($lastBackup, $oneDrive),
            'last_backup' => $lastBackup,
            'onedrive' => $oneDrive,
            'cloud_sync' => $cloudSync,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unreadable(string $path): array
    {
        return [
            'readable' => false,
            'path' => $path,
            'updated_at' => null,
            'headline' => 'Không đọc được trạng thái backup hiện tại.',
            'last_backup' => $this->emptyBlock(),
            'onedrive' => $this->emptyBlock(),
            'cloud_sync' => $this->emptyBlock(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        if (! is_string($path) || $path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>|null  $block
     * @return array<string, mixed>
     */
    private function normalizeStatusBlock(?array $block): array
    {
        if (! is_array($block)) {
            return $this->emptyBlock();
        }

        $status = strtoupper((string) ($block['status'] ?? 'UNKNOWN'));

        if (! in_array($status, ['SUCCESS', 'FAILED', 'PENDING', 'UNKNOWN'], true)) {
            $status = 'UNKNOWN';
        }

        return [
            'status' => $status,
            'label' => self::STATUS_LABELS[$status],
            'set_id' => isset($block['set_id']) ? (string) $block['set_id'] : null,
            'time' => isset($block['time']) && $block['time'] !== null ? (string) $block['time'] : null,
            'error' => isset($block['error']) && $block['error'] !== null ? (string) $block['error'] : null,
            'note' => isset($block['note']) && $block['note'] !== null ? (string) $block['note'] : null,
            'checked_at' => isset($block['checked_at']) && $block['checked_at'] !== null ? (string) $block['checked_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBlock(): array
    {
        return [
            'status' => 'UNKNOWN',
            'label' => self::STATUS_LABELS['UNKNOWN'],
            'set_id' => null,
            'time' => null,
            'error' => null,
            'note' => null,
            'checked_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $files
     */
    private function verifiedFileCount(?array $files): int
    {
        if (! is_array($files)) {
            return 0;
        }

        return count(array_filter($files, static fn ($f) => ($f['verified'] ?? false) === true));
    }

    /**
     * @param  array<string, mixed>|null  $databases
     */
    private function databaseCount(?array $databases): int
    {
        return is_array($databases) ? count($databases) : 0;
    }

    /**
     * @param  array<string, mixed>|null  $files
     */
    private function totalSize(?array $files): int
    {
        if (! is_array($files)) {
            return 0;
        }

        return array_sum(array_map(static fn ($f) => (int) ($f['size'] ?? 0), $files));
    }

    /**
     * @param  array<string, mixed>  $cloudSync
     * @param  array<string, mixed>  $oneDrive
     */
    private function cloudSyncNote(array $cloudSync, array $oneDrive, ?string $rawNote): string
    {
        if ($oneDrive['status'] === 'SUCCESS') {
            return self::CLOUD_UNVERIFIED_NOTE;
        }

        return (string) ($cloudSync['note'] ?: $rawNote ?: 'Chưa có dữ liệu để xác minh.');
    }

    /**
     * @param  array<string, mixed>  $lastBackup
     * @param  array<string, mixed>  $oneDrive
     */
    private function headline(array $lastBackup, array $oneDrive): string
    {
        if ($lastBackup['status'] === 'FAILED') {
            return 'Backup database gần nhất thất bại — kiểm tra ngay.';
        }

        if ($lastBackup['status'] === 'SUCCESS' && $oneDrive['status'] === 'SUCCESS') {
            return 'Backup thành công và đã copy vào thư mục OneDrive.';
        }

        if ($lastBackup['status'] === 'SUCCESS') {
            return 'Backup database thành công.';
        }

        return 'Chưa có thông tin trạng thái backup.';
    }
}
