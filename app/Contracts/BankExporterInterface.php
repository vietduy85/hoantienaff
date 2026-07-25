<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface BankExporterInterface
{
    /**
     * Validate the template file is readable and has the expected structure.
     *
     * @throws \RuntimeException if template is invalid
     */
    public function validateTemplate(): void;

    /**
     * Validate that all withdraw requests have the required data for export.
     *
     * @throws \InvalidArgumentException if any request is missing required fields
     */
    public function validateData(Collection $requests): void;

    /**
     * Generate a temporary Excel file from the given withdraw requests.
     *
     * Must call validateTemplate() and validateData() internally.
     * Returns the absolute path to the temp file on success.
     * Throws on any failure — caller must not proceed with DB changes.
     */
    public function generateTempFile(Collection $requests): string;

    /**
     * The download filename (e.g. "25072026Chuyenkhoantheobangke.xlsx").
     */
    public function getDownloadFilename(): string;
}
