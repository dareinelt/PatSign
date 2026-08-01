<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Core\Request;
use App\Security\CsrfTokenManager;
use App\Services\SettingsService;
use App\Services\SystemStatusService;

final class DashboardController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DocumentRepository $documents,
        private readonly AuditLogRepository $auditLogs,
        private readonly SystemStatusService $systemStatus,
        private readonly SettingsService $settings,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    public function index(): Response
    {
        return $this->render('dashboard.index', [
            'csrf' => $this->csrf->token(),
            'user' => $_SESSION['auth_user'] ?? [],
            'clinicName' => $this->settings->getString('general.clinic_name', $this->settings->getString('app.name', 'PatSign')),
            'waitingPatients' => $this->safeCall(fn () => $this->documents->waitingPatients()),
            'statusCounts' => $this->safeCall(fn () => $this->documents->countsByStatus()),
            'activities' => $this->safeCall(fn () => $this->auditLogs->latest(15)),
            'systemChecks' => $this->systemStatus->checkAll(),
        ]);
    }

    public function data(): Response
    {
        return $this->json([
            'waitingPatients' => $this->safeCall(fn () => $this->documents->waitingPatients()),
            'statusCounts' => $this->safeCall(fn () => $this->documents->countsByStatus()),
            'activities' => $this->safeCall(fn () => $this->auditLogs->latest(15)),
            'systemChecks' => $this->systemStatus->checkAll(),
        ]);
    }

    public function search(Request $request): Response
    {
        $term = trim((string) $request->input('q', ''));
        if ($term === '') {
            return $this->json(['results' => []]);
        }

        return $this->json(['results' => $this->safeCall(fn () => $this->documents->search($term))]);
    }

    private function safeCall(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return [];
        }
    }
}
