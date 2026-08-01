<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\SignatureRepository;
use App\Security\CsrfTokenManager;
use App\Services\MailService;
use App\Services\SettingsService;
use App\Services\SignatureService;

/**
 * Patientenmodus: Das Personal startet per Fallnummer eine Patientensitzung,
 * danach durchläuft der Patient einen Wizard bis zur Unterschrift.
 */
final class PatientController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DocumentRepository $documents,
        private readonly SignatureRepository $signatures,
        private readonly SignatureService $signatureService,
        private readonly AuditLogRepository $auditLogs,
        private readonly SettingsService $settings,
        private readonly MailService $mail,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    /** Personal startet den Patientenmodus für eine Fallnummer. */
    public function start(Request $request): Response
    {
        $caseNumber = trim((string) $request->input('case_number'));
        if ($caseNumber === '') {
            return $this->json(['error' => 'Fallnummer erforderlich'], 422);
        }

        $documents = $this->documents->findByCaseNumber($caseNumber);
        if ($documents === []) {
            return $this->json(['error' => 'Keine Dokumente zu dieser Fallnummer gefunden'], 404);
        }

        $_SESSION['patient_session'] = [
            'case_number' => $caseNumber,
            'operator' => (string) ($_SESSION['auth_user']['username'] ?? ''),
            'started_at' => time(),
        ];

        $this->audit('patient_session_started', ['case_number' => $caseNumber]);

        return Response::redirect('/patient');
    }

    public function wizard(): Response
    {
        $session = $this->patientSession();
        $documents = $this->documents->findByCaseNumber((string) $session['case_number']);

        $first = $documents[0] ?? [];

        return $this->render('patient.wizard', [
            'csrf' => $this->csrf->token(),
            'caseNumber' => (string) $session['case_number'],
            'patientName' => trim((string) ($first['first_name'] ?? '') . ' ' . (string) ($first['last_name'] ?? '')),
            'documents' => $documents,
            'clinicName' => $this->settings->getString('general.clinic_name', $this->settings->getString('app.name', 'PatSign')),
        ]);
    }

    /** Streamt ein PDF der aktuellen Patientensitzung an den nativen Viewer. */
    public function document(Request $request): Response
    {
        $session = $this->patientSession();
        $id = (int) $request->input('id');
        $document = $this->documents->findById($id);

        if ($document === null || (string) $document['case_number'] !== (string) $session['case_number']) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
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

    /** Schließt die Signatur für alle Dokumente der Sitzung ab. */
    public function sign(Request $request): Response
    {
        $session = $this->patientSession();
        $caseNumber = (string) $session['case_number'];

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

        $documents = $this->documents->findByCaseNumber($caseNumber);
        if ($documents === []) {
            return $this->json(['error' => 'Keine Dokumente gefunden.'], 404);
        }

        $clinic = $this->settings->getString('general.clinic_name', 'PatSign');
        $operator = (string) ($session['operator'] ?? '');
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

            $this->signatures->create([
                'document_id' => (int) $document['id'],
                'completion_page_path' => $exportPath . '/' . $finalName,
                'signed_pdf_path' => $exportPath . '/' . $finalName,
                'consent_email' => (int) $emailConsent,
                'email_address' => $emailConsent ? $email : null,
                'signed_at' => $signedAt,
                'signature_data' => $signatureData,
                'operator_name' => $operator,
                'clinic_name' => $clinic,
            ]);

            $this->documents->updateStatus((int) $document['id'], 'signed');
        }

        $this->audit('documents_signed', ['case_number' => $caseNumber, 'count' => count($documents), 'email_consent' => $emailConsent]);

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
                $this->audit('mail_sent', ['case_number' => $caseNumber, 'email' => $email]);
            } catch (\Throwable $e) {
                $this->audit('mail_error', ['case_number' => $caseNumber, 'error' => $e->getMessage()]);
            }
        }

        return $this->json([
            'message' => 'Signatur abgeschlossen',
            'email_sent' => $emailSent,
        ]);
    }

    /** Beendet den Patientenmodus (zurück zum Personal). */
    public function exit(): Response
    {
        unset($_SESSION['patient_session']);

        if (isset($_SESSION['auth_user'])) {
            return Response::redirect('/dashboard');
        }

        return Response::redirect('/login');
    }

    /** @return array<string,mixed> */
    private function patientSession(): array
    {
        $session = $_SESSION['patient_session'] ?? null;

        return is_array($session) ? $session : [];
    }

    /** @param array<string,mixed> $context */
    private function audit(string $event, array $context): void
    {
        try {
            $this->auditLogs->log($event, $context, isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null);
        } catch (\Throwable) {
            // Auditausfall darf den Prozess nicht stoppen.
        }
    }
}
