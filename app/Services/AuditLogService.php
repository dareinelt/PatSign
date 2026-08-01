<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class AuditLogService
{
    private Logger $logger;

    public function __construct(string $logFile)
    {
        $this->logger = new Logger('audit');
        $this->logger->pushHandler(new StreamHandler($logFile, Level::Info));
    }

    /** @param array<string,mixed> $context */
    public function log(string $event, array $context = []): void
    {
        $this->logger->info($event, $context);
    }
}
