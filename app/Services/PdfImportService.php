<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PdfImportService
{
    /** @param array<int,string> $allowedMimeTypes */
    public function __construct(
        private readonly string $importPath,
        private readonly int $maxUploadBytes,
        private readonly array $allowedMimeTypes
    ) {}

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
        if (!move_uploaded_file($tmpFile, $target)) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        return $target;
    }

    /** @return array<int,string> */
    public function importFromWatchFolder(): array
    {
        $files = glob(rtrim($this->importPath, '/') . '/*.pdf') ?: [];
        return array_values($files);
    }
}
