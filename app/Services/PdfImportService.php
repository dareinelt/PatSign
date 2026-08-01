<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PdfImportService
{
    /**
     * @param array<int,string> $allowedMimeTypes
     * @param array{domain:string,username:string,password:string}|null $shareCredentials
     */
    public function __construct(
        private readonly string $importPath,
        private readonly int $maxUploadBytes,
        private readonly array $allowedMimeTypes,
        private readonly ?NetworkShareService $networkShare = null,
        private readonly ?array $shareCredentials = null
    ) {}

    private function connectShare(): void
    {
        if ($this->networkShare === null) {
            return;
        }

        $this->networkShare->ensureConnection(
            $this->importPath,
            $this->shareCredentials['domain'] ?? '',
            $this->shareCredentials['username'] ?? '',
            $this->shareCredentials['password'] ?? ''
        );
    }

    public function importUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen.');
        }

        if ((int) ($file['size'] ?? 0) > $this->maxUploadBytes) {
            throw new RuntimeException('Datei überschreitet das Größenlimit.');
        }

        $tmpFile = (string) ($file['tmp_name'] ?? '');
        $mime = mime_content_type($tmpFile) ?: '';
        if (!in_array($mime, $this->allowedMimeTypes, true)) {
            throw new RuntimeException('Dateityp nicht erlaubt.');
        }

        $target = rtrim($this->importPath, '/') . '/' . basename((string) ($file['name'] ?? uniqid('upload_', true) . '.pdf'));
        $this->connectShare();
        $this->ensureImportDirectory();
        if (!@move_uploaded_file($tmpFile, $target)) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        return $target;
    }

    private function ensureImportDirectory(): void
    {
        $dir = rtrim($this->importPath, '/');
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Import-Verzeichnis konnte nicht erstellt werden: ' . $dir);
        }
    }

    /** @return array<int,string> */
    public function importFromWatchFolder(): array
    {
        $this->connectShare();
        $files = glob(rtrim($this->importPath, '/') . '/*.pdf') ?: [];
        return array_values($files);
    }
}
