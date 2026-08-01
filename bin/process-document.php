<?php

declare(strict_types=1);

/**
 * Hintergrund-Worker: analysiert ein importiertes Dokument.
 * Aufruf: php bin/process-document.php <document_id>
 */

use App\Core\ApplicationFactory;
use App\Services\DocumentProcessingService;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$documentId = (int) ($argv[1] ?? 0);
if ($documentId <= 0) {
    fwrite(STDERR, "Usage: php bin/process-document.php <document_id>\n");
    exit(1);
}

$container = ApplicationFactory::createContainer(dirname(__DIR__));
$container->get(DocumentProcessingService::class)->process($documentId);
