<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Repositories\AuditLogRepository;
use App\Repositories\DeviceAssignmentRepository;
use App\Repositories\DeviceHistoryRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\DeviceSessionRepository;
use App\Repositories\DocumentRepository;
use App\Security\DeviceTokenManager;

/**
 * Lebenszyklus registrierter Signaturgeräte (iPads im Kioskmodus):
 * Registrierung, gerätegebundene Token-Authentifizierung, Zuweisung von
 * Patientenmappen, Sitzungen, automatische Freigabe und Protokollierung.
 */
final class DeviceService
{
    public const ASSIGNMENT_TTL_MINUTES = 30;
    public const SESSION_TTL_MINUTES = 30;
    public const OFFLINE_AFTER_SECONDS = 120;

    public const COOKIE_DEVICE_ID = 'PATSIGN_DEVICE_ID';
    public const COOKIE_DEVICE_TOKEN = 'PATSIGN_DEVICE_TOKEN';

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceAssignmentRepository $assignments,
        private readonly DeviceSessionRepository $sessions,
        private readonly DeviceHistoryRepository $history,
        private readonly DocumentRepository $documents,
        private readonly AuditLogRepository $auditLogs,
        private readonly DeviceTokenManager $tokens
    ) {}

    // ---------------------------------------------------------------- Registrierung

    /**
     * Registriert ein neues Gerät. Der Gerätename muss serverseitig eindeutig sein.
     *
     * @param array{device_type?:string,browser?:string,os?:string,fingerprint?:string,software_version?:string} $meta
     * @return array{device_uuid:string,device_token:string,name:string}
     */
    public function register(string $name, array $meta, string $ip): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Bitte einen Gerätenamen mit maximal 120 Zeichen angeben.');
        }
        if ($this->devices->nameExists($name)) {
            throw new \InvalidArgumentException('Der Gerätename ist bereits vergeben. Bitte einen anderen Namen wählen.');
        }

        $uuid = $this->tokens->generateUuid();
        $token = $this->tokens->generateToken();

        $deviceId = $this->devices->create([
            'device_uuid' => $uuid,
            'name' => $name,
            'device_type' => mb_substr(trim((string) ($meta['device_type'] ?? 'tablet')), 0, 60),
            'browser' => mb_substr(trim((string) ($meta['browser'] ?? '')), 0, 190) ?: null,
            'os' => mb_substr(trim((string) ($meta['os'] ?? '')), 0, 190) ?: null,
            'fingerprint' => mb_substr(trim((string) ($meta['fingerprint'] ?? '')), 0, 128) ?: null,
            'token_hash' => $this->tokens->hash($token),
            'software_version' => mb_substr(trim((string) ($meta['software_version'] ?? '')), 0, 60) ?: null,
            'last_ip' => $ip,
        ]);

        $this->log($deviceId, 'device_registered', ['name' => $name, 'ip' => $ip]);

        return ['device_uuid' => $uuid, 'device_token' => $token, 'name' => $name];
    }

    // ---------------------------------------------------------------- Authentifizierung

    /**
     * Authentifiziert ein Gerät über Header (bevorzugt) oder Cookies.
     * Liefert den Gerätedatensatz oder null; der Status wird NICHT gefiltert,
     * damit gesperrte Geräte einen entsprechenden Hinweis anzeigen können.
     */
    public function authenticate(Request $request): ?array
    {
        $uuid = (string) ($request->header('X-Device-Id') ?? $request->cookie(self::COOKIE_DEVICE_ID) ?? '');
        $token = (string) ($request->header('X-Device-Token') ?? $request->cookie(self::COOKIE_DEVICE_TOKEN) ?? '');

        if ($uuid === '' || $token === '' || !$this->tokens->isUuid($uuid)) {
            return null;
        }

        $device = $this->devices->findByUuid($uuid);
        if ($device === null || !$this->tokens->verify($token, (string) $device['token_hash'])) {
            return null;
        }

        $this->devices->touch((int) $device['id'], $request->ip());

        return $device;
    }

    // ---------------------------------------------------------------- Zuweisung (Personal)

    /**
     * Weist einem freien Gerät die komplette Patientenmappe einer Fallnummer zu.
     *
     * @return array{assignment_id:int}
     */
    public function assign(int $deviceId, string $caseNumber, ?int $userId, ?string $username): array
    {
        $this->expireStale();

        $device = $this->devices->findById($deviceId);
        if ($device === null) {
            throw new \InvalidArgumentException('Gerät nicht gefunden.');
        }
        if ((string) $device['status'] !== 'active') {
            throw new \RuntimeException('Das Gerät ist gesperrt oder außer Betrieb.');
        }
        if (!$this->isOnline($device)) {
            throw new \RuntimeException('Das Gerät ist derzeit offline.');
        }
        if ($this->assignments->findOpenForDevice($deviceId) !== null) {
            throw new \RuntimeException('Das Gerät hat bereits eine aktive Patientenmappe.');
        }

        // Bereits unterschriebene Dokumente werden nicht erneut vorgelegt.
        $documents = $this->documents->findUnsignedByCaseNumber($caseNumber);
        if ($documents === []) {
            if ($this->documents->findByCaseNumber($caseNumber) !== []) {
                throw new \InvalidArgumentException('Alle Dokumente dieser Fallnummer sind bereits unterschrieben.');
            }
            throw new \InvalidArgumentException('Keine Dokumente zu dieser Fallnummer gefunden.');
        }

        $first = $documents[0];
        $patientName = trim((string) ($first['first_name'] ?? '') . ' ' . (string) ($first['last_name'] ?? ''));

        $assignmentId = $this->assignments->create([
            'assignment_uuid' => $this->tokens->generateUuid(),
            'device_id' => $deviceId,
            'case_number' => $caseNumber,
            'patient_name' => $patientName !== '' ? $patientName : null,
            'document_ids' => json_encode(array_map(static fn (array $d): int => (int) $d['id'], $documents)),
            'assigned_by' => $userId,
            'ttl' => self::ASSIGNMENT_TTL_MINUTES,
        ]);

        $this->devices->setLastUser($deviceId, $username);
        $this->log($deviceId, 'folder_sent', ['case_number' => $caseNumber, 'assignment_id' => $assignmentId], $userId);
        $this->audit('device_folder_sent', ['device' => $device['name'], 'case_number' => $caseNumber], $userId);

        return ['assignment_id' => $assignmentId];
    }

    // ---------------------------------------------------------------- Kiosk-Zustand

    /**
     * Aktueller Kiosk-Zustand eines authentifizierten Geräts. Bei offener
     * Zuweisung wird die Sitzung gestartet bzw. das Sitzungstoken rotiert
     * (Einmal-Token gegen Replay) und die Mappe ausgeliefert.
     *
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function kioskState(array $device): array
    {
        $this->expireStale();
        $deviceId = (int) $device['id'];

        if ((string) $device['status'] !== 'active') {
            return ['status' => (string) $device['status'] === 'locked' ? 'locked' : 'retired', 'device_name' => (string) $device['name']];
        }

        $assignment = $this->assignments->findOpenForDevice($deviceId);
        if ($assignment === null) {
            return ['status' => 'waiting', 'device_name' => (string) $device['name']];
        }

        $sessionToken = $this->tokens->generateToken();
        $session = $this->sessions->findActiveForDevice($deviceId);

        if ((string) $assignment['status'] === 'pending' || $session === null) {
            $this->assignments->markDelivered((int) $assignment['id']);
            if ($session !== null) {
                $this->sessions->finish((int) $session['id'], 'ended');
            }
            $this->sessions->create([
                'session_uuid' => $this->tokens->generateUuid(),
                'device_id' => $deviceId,
                'assignment_id' => (int) $assignment['id'],
                'token_hash' => $this->tokens->hash($sessionToken),
                'ttl' => self::SESSION_TTL_MINUTES,
            ]);
            $this->log($deviceId, 'session_started', ['assignment_id' => (int) $assignment['id'], 'case_number' => (string) $assignment['case_number']]);
            $this->audit('device_session_started', ['device' => $device['name'], 'case_number' => (string) $assignment['case_number']]);
        } else {
            $this->sessions->rotateToken((int) $session['id'], $this->tokens->hash($sessionToken), self::SESSION_TTL_MINUTES);
        }

        $documents = $this->assignmentDocuments($assignment);

        return [
            'status' => 'assigned',
            'device_name' => (string) $device['name'],
            'session_token' => $sessionToken,
            'assignment' => [
                'assignment_uuid' => (string) $assignment['assignment_uuid'],
                'case_number' => (string) $assignment['case_number'],
                'patient_name' => (string) ($assignment['patient_name'] ?? ''),
                'documents' => array_map(static fn (array $d): array => [
                    'id' => (int) $d['id'],
                    'document_type' => (string) ($d['document_type'] ?? 'Dokument'),
                    'has_form' => !empty($d['is_interactive'])
                        && in_array((string) ($d['form_status'] ?? 'none'), ['analyzed', 'partial', 'complete'], true),
                ], $documents),
            ],
        ];
    }

    /**
     * Prüft für Kiosk-Dokumentzugriffe, ob das Dokument Teil der aktuell
     * zugewiesenen Patientenmappe ist (Schutz vor URL-Manipulation).
     *
     * @param array<string,mixed> $device
     */
    public function authorizedDocument(array $device, int $documentId): ?array
    {
        $this->expireStale();
        if ((string) $device['status'] !== 'active') {
            return null;
        }

        $assignment = $this->assignments->findOpenForDevice((int) $device['id']);
        if ($assignment === null) {
            return null;
        }

        $ids = json_decode((string) $assignment['document_ids'], true);
        if (!is_array($ids) || !in_array($documentId, array_map('intval', $ids), true)) {
            return null;
        }

        return $this->documents->findById($documentId);
    }

    /**
     * Validiert Gerätestatus, aktive Zuweisung und Sitzungstoken für den
     * Signaturabschluss. Liefert [assignment, session] oder wirft eine Exception.
     *
     * @param array<string,mixed> $device
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public function validateSigningContext(array $device, string $sessionToken): array
    {
        $this->expireStale();
        if ((string) $device['status'] !== 'active') {
            throw new \RuntimeException('Gerät ist gesperrt.');
        }

        $deviceId = (int) $device['id'];
        $assignment = $this->assignments->findOpenForDevice($deviceId);
        $session = $this->sessions->findActiveForDevice($deviceId);

        if ($assignment === null || $session === null || (string) $assignment['status'] !== 'active') {
            throw new \RuntimeException('Keine aktive Zuweisung für dieses Gerät.');
        }
        if (!$this->tokens->verify($sessionToken, (string) $session['token_hash'])) {
            throw new \RuntimeException('Sitzungstoken ungültig.');
        }

        $this->sessions->touch((int) $session['id'], self::SESSION_TTL_MINUTES);

        return [$assignment, $session];
    }

    /**
     * Schließt die Sitzung nach erfolgreicher Unterschrift ab: Mappe schließen,
     * Zuweisung abschließen, Sitzungstoken entwerten, Gerätetoken rotieren
     * (Replay-Schutz) und Gerät freigeben.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $assignment
     * @param array<string,mixed> $session
     * @return string Neues Gerätetoken
     */
    public function completeSigning(array $device, array $assignment, array $session): string
    {
        $deviceId = (int) $device['id'];

        $this->assignments->markCompleted((int) $assignment['id']);
        $this->sessions->finish((int) $session['id'], 'completed');

        $newToken = $this->tokens->generateToken();
        $this->devices->updateTokenHash($deviceId, $this->tokens->hash($newToken));

        $this->log($deviceId, 'signature_completed', ['case_number' => (string) $assignment['case_number'], 'assignment_id' => (int) $assignment['id']]);
        $this->log($deviceId, 'token_renewed', []);
        $this->log($deviceId, 'session_ended', ['reason' => 'completed', 'assignment_id' => (int) $assignment['id']]);
        $this->audit('device_signature_completed', ['device' => $device['name'], 'case_number' => (string) $assignment['case_number']]);

        return $newToken;
    }

    /** @param array<string,mixed> $device */
    public function heartbeat(array $device, ?string $softwareVersion, string $ip): void
    {
        $this->devices->touch((int) $device['id'], $ip, $softwareVersion);
        $session = $this->sessions->findActiveForDevice((int) $device['id']);
        if ($session !== null) {
            $this->sessions->touch((int) $session['id'], self::SESSION_TTL_MINUTES);
        }
    }

    // ---------------------------------------------------------------- Verwaltung

    /** Beendet aktive Sitzung und offene Zuweisungen eines Geräts (Admin/Personal). */
    public function endSession(int $deviceId, ?int $userId, string $reason = 'admin'): void
    {
        $this->assignments->cancelOpenForDevice($deviceId);
        $this->sessions->endOpenForDevice($deviceId);
        $this->log($deviceId, 'session_ended', ['reason' => $reason], $userId);
        $this->audit('device_session_ended', ['device_id' => $deviceId, 'reason' => $reason], $userId);
    }

    public function rename(int $deviceId, string $name, ?int $userId): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Bitte einen gültigen Gerätenamen angeben.');
        }
        if ($this->devices->nameExists($name, $deviceId)) {
            throw new \InvalidArgumentException('Der Gerätename ist bereits vergeben.');
        }
        $this->devices->rename($deviceId, $name);
        $this->log($deviceId, 'device_renamed', ['name' => $name], $userId);
        $this->audit('device_renamed', ['device_id' => $deviceId, 'name' => $name], $userId);
    }

    public function setStatus(int $deviceId, string $status, ?int $userId): void
    {
        if (!in_array($status, ['active', 'locked', 'retired'], true)) {
            throw new \InvalidArgumentException('Ungültiger Status.');
        }
        if ($status !== 'active') {
            $this->endSession($deviceId, $userId, $status);
        }
        $this->devices->updateStatus($deviceId, $status);
        $event = match ($status) {
            'active' => 'device_activated',
            'locked' => 'device_locked',
            'retired' => 'device_retired',
        };
        $this->log($deviceId, $event, [], $userId);
        $this->audit($event, ['device_id' => $deviceId], $userId);
    }

    /** Setzt ein Gerät zurück: Sitzung/Zuweisung beenden, Token entwerten (erzwingt Neuregistrierung). */
    public function reset(int $deviceId, ?int $userId): void
    {
        $this->endSession($deviceId, $userId, 'reset');
        $this->devices->updateTokenHash($deviceId, $this->tokens->hash($this->tokens->generateToken()));
        $this->log($deviceId, 'device_reset', [], $userId);
        $this->audit('device_reset', ['device_id' => $deviceId], $userId);
    }

    public function delete(int $deviceId, ?int $userId): void
    {
        $device = $this->devices->findById($deviceId);
        $this->devices->delete($deviceId);
        $this->log(null, 'device_deleted', ['device_id' => $deviceId, 'name' => (string) ($device['name'] ?? '')], $userId);
        $this->audit('device_deleted', ['device_id' => $deviceId, 'name' => (string) ($device['name'] ?? '')], $userId);
    }

    // ---------------------------------------------------------------- Übersichten

    /** @return array<int,array<string,mixed>> Geräte inkl. Verfügbarkeit */
    public function overview(): array
    {
        $this->expireStale();

        return array_map(function (array $device): array {
            $device['availability'] = $this->availability($device);

            return $device;
        }, $this->devices->overview());
    }

    /** @return array<int,array<string,mixed>> Nur freie, auswählbare Geräte */
    public function freeDevices(): array
    {
        return array_values(array_filter($this->overview(), static fn (array $d): bool => $d['availability'] === 'free'));
    }

    /** @return array<int,array<string,mixed>> */
    public function activeSessions(): array
    {
        $this->expireStale();

        return $this->sessions->active();
    }

    /** @return array<int,array<string,mixed>> */
    public function assignmentLog(int $limit = 50): array
    {
        return $this->assignments->latest($limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function historyLog(int $limit = 50): array
    {
        return $this->history->latest($limit);
    }

    /** Setzt überfällige Zuweisungen/Sitzungen zurück und protokolliert Timeouts. */
    public function expireStale(): void
    {
        foreach ($this->assignments->expireStale() as $expired) {
            $this->log((int) $expired['device_id'], 'device_timeout', ['assignment_id' => (int) $expired['id']]);
        }
        $this->sessions->expireStale();
    }

    /** @param array<string,mixed> $device */
    public function isOnline(array $device): bool
    {
        $lastSeen = $device['last_seen_at'] ?? null;

        return is_string($lastSeen) && (time() - strtotime($lastSeen)) <= self::OFFLINE_AFTER_SECONDS;
    }

    /** @param array<string,mixed> $device frei | belegt | offline | gesperrt/außer Betrieb */
    private function availability(array $device): string
    {
        if ((string) $device['status'] === 'locked') {
            return 'locked';
        }
        if ((string) $device['status'] === 'retired') {
            return 'retired';
        }
        if (!$this->isOnline($device)) {
            return 'offline';
        }

        return $device['assignment_id'] !== null ? 'busy' : 'free';
    }

    /** @param array<string,mixed> $assignment @return array<int,array<string,mixed>> */
    public function assignmentDocuments(array $assignment): array
    {
        $ids = json_decode((string) $assignment['document_ids'], true);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        $documents = [];
        foreach ($ids as $id) {
            $document = $this->documents->findById($id);
            // Zwischenzeitlich unterschriebene Dokumente nicht erneut vorlegen.
            if ($document !== null && !in_array((string) $document['status'], ['signed', 'sent', 'archived'], true)) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /** @param array<string,mixed> $context */
    private function log(?int $deviceId, string $event, array $context, ?int $userId = null): void
    {
        try {
            $this->history->log($deviceId, $event, $context, $userId);
        } catch (\Throwable) {
            // Protokollausfall darf den Betrieb nicht stoppen.
        }
    }

    /** @param array<string,mixed> $context */
    private function audit(string $event, array $context, ?int $userId = null): void
    {
        try {
            $this->auditLogs->log($event, $context, $userId);
        } catch (\Throwable) {
        }
    }
}
