<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\DeviceService;

/**
 * Geräte-Endpunkte für das medizinische Personal:
 * Geräteübersicht im Dashboard und "Patientenmappe an Gerät senden".
 */
final class DeviceController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DeviceService $devices
    ) {
        parent::__construct($view);
    }

    /** Geräteübersicht (Dashboard-Widget + Auswahldialog). */
    public function overview(): Response
    {
        $devices = array_map(static fn (array $d): array => [
            'id' => (int) $d['id'],
            'name' => (string) $d['name'],
            'status' => (string) $d['status'],
            'availability' => (string) $d['availability'],
            'last_seen_at' => $d['last_seen_at'],
            'assigned_patient' => $d['assigned_patient'] ?? null,
            'assigned_case_number' => $d['assigned_case_number'] ?? null,
            'last_user' => $d['last_user'] ?? null,
            'browser' => $d['browser'] ?? null,
            'os' => $d['os'] ?? null,
            'software_version' => $d['software_version'] ?? null,
        ], $this->devices->overview());

        return $this->json(['devices' => $devices]);
    }

    /** Sendet die komplette Patientenmappe einer Fallnummer an ein freies Gerät. */
    public function assign(Request $request): Response
    {
        $caseNumber = trim((string) $request->input('case_number', ''));
        $deviceId = (int) $request->input('device_id');

        if ($caseNumber === '' || $deviceId <= 0) {
            return $this->json(['error' => 'Fallnummer und Gerät sind erforderlich.'], 422);
        }

        $user = $_SESSION['auth_user'] ?? [];

        try {
            $result = $this->devices->assign(
                $deviceId,
                $caseNumber,
                isset($user['id']) ? (int) $user['id'] : null,
                isset($user['username']) ? (string) $user['username'] : null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json(['message' => 'Patientenmappe wurde an das Gerät gesendet.', 'assignment_id' => $result['assignment_id']]);
    }
}
