<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;
use App\Repositories\HealthCheckHistoryRepository;
use App\Services\SystemStatusService;

/**
 * Öffentliche Healthcheck-Seite (/health) mit Ampeldarstellung aller
 * Systemkomponenten und einem Zeitstrahl der letzten 48 Stunden.
 */
final class HealthController extends BaseController
{
    public function __construct(
        View $view,
        private readonly SystemStatusService $systemStatus,
        private readonly HealthCheckHistoryRepository $history
    ) {
        parent::__construct($view);
    }

    public function index(): Response
    {
        $checks = $this->runAndRecord();

        return $this->render('health.index', [
            'checks' => $checks,
            'overall' => $this->overallStatus($checks),
            'timeline' => $this->buildTimeline($checks),
            'generatedAt' => date('d.m.Y H:i:s'),
        ], $this->httpStatus($checks));
    }

    public function data(): Response
    {
        $checks = $this->runAndRecord();

        return $this->json([
            'status' => $this->overallStatus($checks),
            'checks' => $checks,
            'timeline' => $this->buildTimeline($checks),
            'generated_at' => date(DATE_ATOM),
        ], $this->httpStatus($checks));
    }

    /** @return array<int,array{key:string,label:string,status:string,detail:string}> */
    private function runAndRecord(): array
    {
        $checks = $this->systemStatus->checkAll();
        $this->history->record($checks);
        $this->history->prune();

        return $checks;
    }

    /** @param array<int,array{key:string,label:string,status:string,detail:string}> $checks */
    private function overallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('error', $statuses, true)) {
            return 'error';
        }

        return in_array('warn', $statuses, true) ? 'warn' : 'ok';
    }

    /** @param array<int,array{key:string,label:string,status:string,detail:string}> $checks */
    private function httpStatus(array $checks): int
    {
        return $this->overallStatus($checks) === 'error' ? 503 : 200;
    }

    /**
     * Baut je Komponente 48 Stunden-Buckets (älteste zuerst).
     * Status "none" bedeutet: keine Messung in dieser Stunde.
     *
     * @param array<int,array{key:string,label:string,status:string,detail:string}> $checks
     * @return array<int,array{key:string,label:string,slots:array<int,array{hour:string,status:string}>}>
     */
    private function buildTimeline(array $checks): array
    {
        $history = $this->history->timelineLast48h();
        $now = new \DateTimeImmutable('now');

        $buckets = [];
        for ($i = 47; $i >= 0; $i--) {
            $slot = $now->sub(new \DateInterval('PT' . $i . 'H'));
            $buckets[] = ['key' => $slot->format('Y-m-d H'), 'label' => $slot->format('d.m. H:00')];
        }

        $timeline = [];
        foreach ($checks as $check) {
            $slots = [];
            foreach ($buckets as $bucket) {
                $slots[] = [
                    'hour' => $bucket['label'],
                    'status' => $history[$check['key']][$bucket['key']] ?? 'none',
                ];
            }
            $timeline[] = ['key' => $check['key'], 'label' => $check['label'], 'slots' => $slots];
        }

        return $timeline;
    }
}
