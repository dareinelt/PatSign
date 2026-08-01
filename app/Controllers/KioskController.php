<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CsrfTokenManager;
use App\Services\DeviceService;
use App\Services\CompletionPageService;
use App\Services\Forms\FilledPdfService;
use App\Services\Forms\FormResponseService;
use App\Services\SettingsService;
use App\Services\SignatureService;
use App\Repositories\DocumentRepository;
use App\Repositories\SignatureRepository;
use App\Services\MailService;

/**
 * Kioskmodus für registrierte Signaturgeräte (iPads).
 * Jede Anfrage wird über Geräte-ID + gerätegebundenes Token authentifiziert;
 * ohne gültige aktive Zuweisung liefern Dokument-/Signaturendpunkte HTTP 403.
 */
final class KioskController extends BaseController
{
    private const POLL_SECONDS = 20;

    public function __construct(
        View $view,
        private readonly DeviceService $devices,
        private readonly DocumentRepository $documents,
        private readonly SignatureRepository $signatures,
        private readonly SignatureService $signatureService,
        private readonly CompletionPageService $completionPage,
        private readonly SettingsService $settings,
        private readonly MailService $mail,
        private readonly CsrfTokenManager $csrf,
        private readonly Config $config,
        private readonly FormResponseService $forms,
        private readonly FilledPdfService $filledPdf
    ) {
        parent::__construct($view);
    }

    /** Einstieg: Registrierungsassistent oder Kioskoberfläche. */
    public function index(Request $request): Response
    {
        $clinicName = $this->settings->getString('general.clinic_name', $this->settings->getString('app.name', 'PatSign'));
        $device = $this->devices->authenticate($request);

        if ($device === null) {
            return $this->render('kiosk.register', [
                'csrf' => $this->csrf->token(),
                'clinicName' => $clinicName,
            ]);
        }

        return $this->render('kiosk.kiosk', [
            'csrf' => $this->csrf->token(),
            'clinicName' => $clinicName,
            'deviceName' => (string) $device['name'],
            'deviceStatus' => (string) $device['status'],
        ]);
    }

    /** Erstregistrierung eines Geräts (eindeutiger Name wird serverseitig geprüft). */
    public function register(Request $request): Response
    {
        try {
            $credentials = $this->devices->register(
                (string) $request->input('name', ''),
                [
                    'device_type' => (string) $request->input('device_type', 'tablet'),
                    'browser' => (string) $request->input('browser', ''),
                    'os' => (string) $request->input('os', ''),
                    'fingerprint' => (string) $request->input('fingerprint', ''),
                    'software_version' => (string) $request->input('software_version', ''),
                ],
                $request->ip()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        $this->setDeviceCookies($credentials['device_uuid'], $credentials['device_token']);

        return $this->json($credentials, 201);
    }

    /** Stellt Geräte-Cookies aus vorhandenen Header-Credentials wieder her. */
    public function reconnect(Request $request): Response
    {
        $device = $this->devices->authenticate($request);
        if ($device === null) {
            return $this->json(['error' => 'Gerät nicht autorisiert'], 401);
        }

        $this->setDeviceCookies((string) $device['device_uuid'], (string) $request->header('X-Device-Token'));

        return $this->json(['ok' => true]);
    }

    /** Aktueller Kiosk-Zustand (liefert bei Zuweisung die Patientenmappe + Sitzungstoken). */
    public function state(Request $request): Response
    {
        $device = $this->devices->authenticate($request);
        if ($device === null) {
            return $this->json(['error' => 'Gerät nicht autorisiert'], 401);
        }

        return $this->json($this->devices->kioskState($device));
    }

    /**
     * Long Polling: hält die Anfrage bis zu 20 Sekunden, bis eine Zuweisung
     * eintrifft. Funktioniert ohne Zusatzdienste im lokalen Krankenhausnetz.
     */
    public function poll(Request $request): Response
    {
        $device = $this->devices->authenticate($request);
        if ($device === null) {
            return $this->json(['error' => 'Gerät nicht autorisiert'], 401);
        }

        // Session freigeben, damit parallele Anfragen nicht blockieren.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $deadline = time() + self::POLL_SECONDS;
        do {
            $state = $this->devices->kioskState($device);
            if ($state['status'] !== 'waiting') {
                return $this->json($state);
            }
            if (time() >= $deadline) {
                break;
            }
            sleep(2);
        } while (true);

        return $this->json($state);
    }

    /** Lebenszeichen des Geräts (aktualisiert letzte Aktivität und Sitzungsablauf). */
    public function heartbeat(Request $request): Response
    {
        $device = $this->devices->authenticate($request);
        if ($device === null) {
            return $this->json(['error' => 'Gerät nicht autorisiert'], 401);
        }

        $this->devices->heartbeat($device, (string) $request->input('software_version', '') ?: null, $request->ip());

        return $this->json(['status' => (string) $device['status']]);
    }

    /** Streamt ausschließlich Dokumente der aktuell zugewiesenen Patientenmappe. */
    public function document(Request $request): Response
    {
        $device = $this->devices->authenticate($request);
        if ($device === null) {
            return new Response('Gerät nicht autorisiert', 401, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $document = $this->devices->authorizedDocument($device, (int) $request->input('id'));
        if ($document === null) {
            return new Response('Zugriff verweigert', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $path = (string) $document['original_path'];
        if (!is_file($path)) {
            return new Response('Datei nicht verfügbar', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dokument.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Signaturabschluss: speichert Unterschriften und gibt das Gerät automatisch frei. */
    public function sign(Request $request): Response
    {
        $device = $this->devices->authenticate($request);
        if ($device === null) {
            return $this->json(['error' => 'Gerät nicht autorisiert'], 401);
        }

        try {
            [$assignment, $session] = $this->devices->validateSigningContext($device, (string) $request->header('X-Session-Token', ''));
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 403);
        }

        if (!filter_var($request->input('read_confirmed', false), FILTER_VALIDATE_BOOL)) {
            return $this->json(['error' => 'Bitte bestätigen Sie, dass Sie alle Dokumente gelesen haben.'], 422);
        }

        $signatureData = (string) $request->input('signature_data', '');
        if ($signatureData === '') {
            return $this->json(['error' => 'Bitte unterschreiben Sie im Unterschriftsfeld.'], 422);
        }

        $emailConsent = filter_var($request->input('email_consent', false), FILTER_VALIDATE_BOOL);
        $email = trim((string) $request->input('email', ''));
        if ($emailConsent && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'], 422);
        }

        $documents = $this->devices->assignmentDocuments($assignment);
        if ($documents === []) {
            return $this->json(['error' => 'Keine Dokumente gefunden.'], 404);
        }

        // Interaktive Dokumente: Pflichtfelder serverseitig prüfen und die
        // ausgefüllte Version für die Zusammenführung erzeugen.
        $formSources = [];
        foreach ($documents as $document) {
            $documentId = (int) $document['id'];
            if (empty($document['is_interactive']) || !$this->forms->hasReadyForm($documentId)) {
                continue;
            }
            try {
                $state = $this->forms->complete($document);
                if (!$state['valid']) {
                    return $this->json(['error' => 'Bitte füllen Sie zuerst alle Pflichtfelder des Formulars aus.'], 422);
                }
                $resolved = $this->forms->resolvedFields($document);
                $filled = $this->filledPdf->render($document, $resolved['fields']);
                $formSources[$documentId] = $filled + ['response_id' => (int) $resolved['response']['id']];
            } catch (\RuntimeException $e) {
                return $this->json(['error' => $e->getMessage()], 422);
            }
        }

        $clinic = $this->settings->getString('general.clinic_name', 'PatSign');
        $operator = $this->assignmentOperator($assignment);
        $signedAt = date('Y-m-d H:i:s');
        $exportPath = rtrim($this->settings->getString('app.network_share_path'), '/');

        foreach ($documents as $document) {
            $finalName = $this->signatureService->buildFinalFilename([
                'case_number' => (string) ($document['case_number'] ?? ''),
                'last_name' => (string) ($document['last_name'] ?? ''),
                'first_name' => (string) ($document['first_name'] ?? ''),
                'birth_date' => (string) ($document['birth_date'] ?? ''),
                'document_type' => (string) ($document['document_type'] ?? 'Unbekannt'),
            ]);

            // Abschlussseite erzeugen und an das Dokument anhängen.
            $documentId = (int) $document['id'];
            $formSource = $formSources[$documentId] ?? null;
            $paths = [
                'signed_pdf_path' => $exportPath . '/' . $finalName,
                'completion_page_path' => $exportPath . '/' . $finalName,
            ];
            try {
                $paths = $this->completionPage->appendToDocument($document, [
                    'signature_data' => $signatureData,
                    'email_consent' => $emailConsent,
                    'email' => $emailConsent ? $email : '',
                    'operator' => $operator,
                    'status' => 'signed',
                    'device' => (string) ($device['name'] ?? ''),
                    'started_at' => (string) ($session['started_at'] ?? ($assignment['assigned_at'] ?? '')),
                    'signed_at' => $signedAt,
                    'final_name' => $finalName,
                    'source_pdf_path' => $formSource !== null ? $formSource['path'] : '',
                ]);
            } catch (\Throwable) {
                // Fehler beim PDF-Aufbau darf die Signatur nicht verhindern.
            }

            // Formularinhalte nach der Unterschrift einfrieren.
            if ($formSource !== null) {
                $this->forms->markSigned($formSource['response_id'], $formSource['filled_document_id'], $paths['signed_pdf_path']);
                $this->documents->updateFormStatus($documentId, 'signed');
                @unlink($formSource['path']);
            }

            $this->signatures->create([
                'document_id' => (int) $document['id'],
                'completion_page_path' => $paths['completion_page_path'],
                'signed_pdf_path' => $paths['signed_pdf_path'],
                'consent_email' => (int) $emailConsent,
                'email_address' => $emailConsent ? $email : null,
                'signed_at' => $signedAt,
                'signature_data' => $signatureData,
                'operator_name' => $operator,
                'clinic_name' => $clinic,
            ]);

            $this->documents->updateStatus((int) $document['id'], 'signed');
        }

        $emailSent = false;
        if ($emailConsent) {
            try {
                $this->mail->send(
                    $email,
                    $clinic . ' – Ihre unterschriebenen Dokumente',
                    "Guten Tag,\n\nIhre Dokumente wurden erfolgreich unterschrieben. Sie erhalten Ihre Unterlagen in Kürze.\n\n" . $clinic
                );
                $emailSent = true;
                foreach ($documents as $document) {
                    $this->documents->updateStatus((int) $document['id'], 'sent');
                }
            } catch (\Throwable) {
                // Mailversand darf den Signaturabschluss nicht verhindern.
            }
        }

        // Automatische Freigabe: Mappe schließen, Tokens entwerten/rotieren.
        $newDeviceToken = $this->devices->completeSigning($device, $assignment, $session);
        $this->setDeviceCookies((string) $device['device_uuid'], $newDeviceToken);

        return $this->json([
            'message' => 'Signatur abgeschlossen',
            'email_sent' => $emailSent,
            'device_token' => $newDeviceToken,
        ]);
    }

    private function assignmentOperator(array $assignment): string
    {
        // Zuweisender Benutzer wurde beim Senden am Gerät hinterlegt.
        return (string) ($assignment['assigned_by_name'] ?? '');
    }

    private function setDeviceCookies(string $uuid, string $token): void
    {
        $options = [
            'expires' => time() + 60 * 60 * 24 * 730,
            'path' => '/',
            'secure' => (bool) $this->config->get('app.security.session_secure', true),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
        setcookie(DeviceService::COOKIE_DEVICE_ID, $uuid, $options);
        setcookie(DeviceService::COOKIE_DEVICE_TOKEN, $token, $options);
    }
}
