<?php

declare(strict_types=1);

/**
 * Hintergrund-Worker: führt die KI-Analyse eines Clearing-Falls erneut aus.
 * Aufruf: php bin/reanalyze-document.php <clearing_case_id> <vision|analysis|both> [<user_id>]
 */

use App\Core\ApplicationFactory;
use App\Services\ClearingService;
use App\Services\DocumentAnalysisService;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$caseId = (int) ($argv[1] ?? 0);
$mode = (string) ($argv[2] ?? 'both');
$userId = (int) ($argv[3] ?? 0);

if ($caseId <= 0 || !in_array($mode, ['vision', 'analysis', 'both'], true)) {
    fwrite(STDERR, "Usage: php bin/reanalyze-document.php <clearing_case_id> <vision|analysis|both> [<user_id>]\n");
    exit(1);
}

$container = ApplicationFactory::createContainer(dirname(__DIR__));
$container->get(ClearingService::class)->performReanalysis(
    $caseId,
    $mode,
    $userId > 0 ? $userId : null,
    $container->get(DocumentAnalysisService::class)
);
