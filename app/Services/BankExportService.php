<?php

namespace App\Services;

use App\Contracts\BankExporterInterface;
use App\Models\User;
use App\Services\BankExports\ChuyenkhoantheobangkeExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BankExportService
{
    private const EXPORTERS = [
        'chuyenkhoantheobangke' => ChuyenkhoantheobangkeExporter::class,
    ];

    public function getExporter(string $template): BankExporterInterface
    {
        if (! isset(self::EXPORTERS[$template])) {
            throw new \InvalidArgumentException("Unknown bank template: {$template}");
        }

        $class = self::EXPORTERS[$template];

        return new $class();
    }

    public function export(string $template, Collection $requests, ?User $admin = null): string
    {
        $exporter = $this->getExporter($template);

        try {
            return $exporter->generateTempFile($requests);
        } catch (\Exception $e) {
            Log::error('Bank export failed', [
                'template' => $template,
                'admin_id' => $admin?->id,
                'admin_username' => $admin?->username,
                'withdraw_request_ids' => $requests->pluck('id')->toArray(),
                'exception' => get_class($e) . ': ' . $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            throw $e;
        }
    }

    public function availableTemplates(): array
    {
        return array_keys(self::EXPORTERS);
    }
}
