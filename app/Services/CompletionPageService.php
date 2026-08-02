<?php

declare(strict_types=1);

namespace App\Services;

use setasign\Fpdi\Fpdi;

/**
 * Erzeugt die Abschlussseite (letzte Seite) für unterschriebene Dokumente
 * und hängt sie an das Original-PDF an.
 *
 * Die Textbausteine sind im Adminbereich (Sektion "Abschlussseite") pflegbar
 * und enthalten Platzhalter, die pro Dokument befüllt werden. Ort und
 * Klinikname stammen aus den allgemeinen Einstellungen.
 */
final class CompletionPageService
{
    public const DEFAULT_HEADER = "Patient: {nachname}, {vorname}\nGeburtsdatum: {geburtsdatum}\nFallnummer: {fallnummer}\nDokumententyp: {dokumententyp}\nExport-Dateiname: {dateiname}";
    public const DEFAULT_BODY = "Der Patient ({nachname}, {vorname}, {geburtsdatum}) befand sich am {datum} zur Behandlung im {klinik}.\n\nAm {datum} um {uhrzeit} Uhr wurde dem Patienten das Dokument ({dokumententyp}) (Export-Dateiname: {dateiname}) zur digitalen Unterschrift vorgelegt.";
    public const DEFAULT_EMAIL_SECTION_TITLE = 'Versand der digitalen Kopie';
    public const DEFAULT_EMAIL_CONSENT = 'Der Patient wünscht den Versand einer digitalen Kopie per E-Mail an: {email}';
    public const DEFAULT_EMAIL_NO_CONSENT = 'Der Patient wünscht keinen Versand einer digitalen Kopie.';
    public const DEFAULT_CONFIRMATION_TITLE = 'Bestätigung';
    public const DEFAULT_SIGNED_STATEMENT = 'Der Patient hat das oben genannte Dokument digital unterschrieben.';
    public const DEFAULT_FOOTER_TITLE = 'Bearbeitungsinformationen';
    public const DEFAULT_FOOTER = "• Bearbeitet durch: {bearbeiter}\n• Beginn des Vorgangs: {beginn} Uhr\n• Document-ID: {document_id}\n• Status des Signaturprozesses: {status}\n• Verwendetes Endgerät: {geraet}";
    public const DEFAULT_KIOSK_EMAIL_NOTICE = "Die Übermittlung erfolgt ausschließlich auf Grundlage Ihrer freiwilligen Einwilligung. Sie können diese Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen. Die Rechtmäßigkeit der bis zum Widerruf erfolgten Datenübermittlungen bleibt hiervon unberührt.\n\nDie Übersendung der Dokumente erfolgt per unverschlüsselter E-Mail. Bei dieser Form der elektronischen Kommunikation kann nicht ausgeschlossen werden, dass Dritte während der Übertragung oder Speicherung Kenntnis vom Inhalt der E-Mail erlangen oder dass die Daten verändert, unvollständig übermittelt oder fehlgeleitet werden. Eine durchgängige Vertraulichkeit und Integrität der übermittelten Informationen kann daher nicht gewährleistet werden. Dies gilt insbesondere, wenn die E-Mail personenbezogene Daten oder besondere Kategorien personenbezogener Daten gemäß Art. 9 DSGVO (insbesondere Gesundheitsdaten) enthält.\n\nMit Ihrer Einwilligung bestätigen Sie, dass Sie über die mit der unverschlüsselten E-Mail-Kommunikation verbundenen Risiken informiert wurden und dennoch die Übermittlung Ihrer Dokumente an die von Ihnen angegebene E-Mail-Adresse wünschen. Sie sind dafür verantwortlich, eine gültige und von Ihnen genutzte E-Mail-Adresse anzugeben sowie Änderungen dieser E-Mail-Adresse unverzüglich mitzuteilen.\n\nIhre E-Mail-Adresse wird ausschließlich zum Zweck der Übermittlung der angeforderten Dokumente sowie gegebenenfalls für damit in unmittelbarem Zusammenhang stehende Kommunikation verarbeitet. Die Verarbeitung erfolgt unter Beachtung der geltenden datenschutzrechtlichen Bestimmungen.\n\nDie Erteilung dieser Einwilligung ist freiwillig. Wenn Sie keine Einwilligung erteilen oder diese später widerrufen, entstehen Ihnen hierdurch keine Nachteile. Ihre Dokumente werden Ihnen in diesem Fall auf einem anderen von der {klinik} angebotenen Übermittlungsweg zur Verfügung gestellt.";

    public function __construct(
        private readonly SettingsService $settings,
        private readonly string $processedPath
    ) {}

    /**
     * Hängt die Abschlussseite an das Originaldokument an und legt das
     * Ergebnis lokal (storage/processed) sowie im Export-Pfad ab.
     *
     * @param array<string,mixed> $document Dokument-Datensatz (documents-Tabelle)
     * @param array<string,mixed> $context  signature_data, email_consent, email,
     *                                      operator, status, device, started_at,
     *                                      signed_at, final_name
     * @return array{signed_pdf_path:string,completion_page_path:string}
     */
    public function appendToDocument(array $document, array $context): array
    {
        $vars = $this->placeholderValues($document, $context);
        $finalName = (string) $context['final_name'];

        if (!is_dir($this->processedPath)) {
            @mkdir($this->processedPath, 0775, true);
        }

        $localPath = rtrim($this->processedPath, '/\\') . DIRECTORY_SEPARATOR . $finalName;
        // Bei interaktiven Formularen wird die serverseitig ausgefüllte
        // Version als Quelle verwendet; das Original bleibt unverändert.
        $originalPath = (string) ($context['source_pdf_path'] ?? '') !== ''
            ? (string) $context['source_pdf_path']
            : (string) ($document['original_path'] ?? '');
        $signatureData = (string) ($context['signature_data'] ?? '');

        $this->buildSignedPdf($originalPath, $localPath, $vars, $signatureData, (bool) ($context['email_consent'] ?? false));

        // Zusätzlich in den Export-Pfad (Netzwerkfreigabe) kopieren.
        $exportPath = rtrim($this->settings->getString('app.network_share_path'), '/\\');
        $exportFile = $localPath;
        if ($exportPath !== '' && is_dir($exportPath)) {
            $target = $exportPath . DIRECTORY_SEPARATOR . $finalName;
            if (@copy($localPath, $target)) {
                $exportFile = $target;
            }
        }

        return [
            'signed_pdf_path' => $localPath,
            'completion_page_path' => $exportFile,
        ];
    }

    /**
     * Befüllt die Platzhalter eines Templates.
     *
     * @param array<string,string> $vars
     */
    public function renderTemplate(string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    public function placeholderValues(array $document, array $context): array
    {
        $signedAt = strtotime((string) ($context['signed_at'] ?? '')) ?: time();
        $startedAt = $context['started_at'] ?? null;
        if (is_string($startedAt) && $startedAt !== '') {
            $startedAt = strtotime($startedAt) ?: $signedAt;
        }
        $startedAt = is_int($startedAt) && $startedAt > 0 ? $startedAt : $signedAt;

        $birthDate = (string) ($document['birth_date'] ?? '');
        if ($birthDate !== '' && ($ts = strtotime($birthDate)) !== false) {
            $birthDate = date('d.m.Y', $ts);
        }

        $email = trim((string) ($context['email'] ?? ''));
        $statusLabels = [
            'imported' => 'Importiert',
            'analyzed' => 'Analysiert',
            'signed' => 'Unterschrieben',
            'sent' => 'Versendet',
            'archived' => 'Archiviert',
        ];
        $status = (string) ($context['status'] ?? 'signed');

        return [
            'nachname' => $this->filled((string) ($document['last_name'] ?? '')),
            'vorname' => $this->filled((string) ($document['first_name'] ?? '')),
            'geburtsdatum' => $this->filled($birthDate),
            'fallnummer' => $this->filled((string) ($document['case_number'] ?? '')),
            'dokumententyp' => (string) (($document['document_type'] ?? '') !== '' ? $document['document_type'] : 'Unbekannt'),
            'dateiname' => $this->filled((string) ($context['final_name'] ?? '')),
            'klinik' => $this->settings->getString('general.clinic_name', 'PatSign'),
            'ort' => $this->filled($this->settings->getString('general.clinic_location', ''), '____________'),
            'datum' => date('d.m.Y', $signedAt),
            'uhrzeit' => date('H:i', $signedAt),
            'email' => $this->filled($email, '_______________________'),
            'bearbeiter' => $this->filled((string) ($context['operator'] ?? '')),
            'beginn' => date('H:i', $startedAt),
            'document_id' => $this->filled((string) ($document['document_id'] ?? '')),
            'status' => $statusLabels[$status] ?? $this->filled($status),
            'geraet' => $this->filled((string) ($context['device'] ?? '')),
        ];
    }

    /** Stellt sicher, dass Platzhalter nie leer ausgegeben werden. */
    private function filled(string $value, string $fallback = 'unbekannt'): string
    {
        return trim($value) !== '' ? $value : $fallback;
    }

    /**
     * Baut das signierte PDF: alle Seiten des Originals plus Abschlussseite.
     * Kann FPDI das Original nicht einlesen (z. B. komprimierte
     * Querverweise), wird die Abschlussseite separat erzeugt und per
     * Ghostscript mit dem Original zusammengeführt.
     *
     * @param array<string,string> $vars
     */
    private function buildSignedPdf(string $originalPath, string $outputPath, array $vars, string $signatureData, bool $emailConsent): void
    {
        try {
            $pdf = new Fpdi();
            if ($originalPath !== '' && is_file($originalPath)) {
                $pageCount = $pdf->setSourceFile($originalPath);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }
            $this->addCompletionPage($pdf, $vars, $signatureData, $emailConsent);
            $pdf->Output('F', $outputPath);
        } catch (\Throwable $e) {
            $this->mergeWithGhostscript($originalPath, $outputPath, $vars, $signatureData, $emailConsent);
        }
    }

    /**
     * Fallback: Abschlussseite als eigenständiges PDF erzeugen und mit
     * Ghostscript an das Original anhängen.
     *
     * @param array<string,string> $vars
     */
    private function mergeWithGhostscript(string $originalPath, string $outputPath, array $vars, string $signatureData, bool $emailConsent): void
    {
        $base = tempnam(sys_get_temp_dir(), 'patsign_page_');
        $pagePath = ($base !== false ? $base : sys_get_temp_dir() . '/patsign_page_' . bin2hex(random_bytes(4))) . '.pdf';
        if ($base !== false) {
            @unlink($base);
        }
        $pdf = new Fpdi();
        $this->addCompletionPage($pdf, $vars, $signatureData, $emailConsent);
        $pdf->Output('F', $pagePath);

        $merged = false;
        if ($originalPath !== '' && is_file($originalPath)) {
            $cmd = sprintf(
                'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=%s %s %s 2>&1',
                escapeshellarg($outputPath),
                escapeshellarg($originalPath),
                escapeshellarg($pagePath)
            );
            exec($cmd, $out, $code);
            $merged = $code === 0 && is_file($outputPath);
        }

        if (!$merged) {
            // Letzte Rückfallebene: Abschlussseite alleine ablegen, damit der
            // Nachweis der Unterschrift in jedem Fall vorhanden ist.
            @copy($pagePath, $outputPath);
        }

        @unlink($pagePath);
    }

    /** @param array<string,string> $vars */
    private function addCompletionPage(Fpdi $pdf, array $vars, string $signatureData, bool $emailConsent): void
    {
        $pdf->AddPage('P', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(20, 15, 20);

        // Kopfblock mit Patientendaten
        $pdf->SetY(15);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $header = $this->settings->getString('completion.header_template', self::DEFAULT_HEADER);
        $pdf->MultiCell(0, 5, $this->encode($this->renderTemplate($header, $vars)));

        // Fließtext
        $pdf->SetY(55);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $body = $this->settings->getString('completion.body_template', self::DEFAULT_BODY);
        $pdf->MultiCell(0, 6, $this->encode($this->renderTemplate($body, $vars)));
        $pdf->Ln(8);

        // Versand der digitalen Kopie (Checkboxen)
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(0, 6, $this->encode($this->settings->getString('completion.email_section_title', self::DEFAULT_EMAIL_SECTION_TITLE)));
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 11);
        $consentText = $this->settings->getString('completion.email_consent_template', self::DEFAULT_EMAIL_CONSENT);
        $noConsentText = $this->settings->getString('completion.email_no_consent_template', self::DEFAULT_EMAIL_NO_CONSENT);
        $this->checkboxLine($pdf, $this->renderTemplate($consentText, $vars), $emailConsent);
        $pdf->Ln(2);
        $this->checkboxLine($pdf, $this->renderTemplate($noConsentText, $vars), !$emailConsent);
        $pdf->Ln(8);

        // Bestätigung
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(0, 6, $this->encode($this->settings->getString('completion.confirmation_title', self::DEFAULT_CONFIRMATION_TITLE)));
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 11);
        $statement = $this->settings->getString('completion.signed_statement', self::DEFAULT_SIGNED_STATEMENT);
        $pdf->MultiCell(0, 6, $this->encode($this->renderTemplate($statement, $vars)));
        $pdf->Ln(10);

        // Digitale Signatur aus dem Prozess
        $pdf->Cell(40, 6, $this->encode('Digitale Signatur:'), 0, 0);
        $signatureFile = $this->signatureImageFile($signatureData);
        if ($signatureFile !== null) {
            $pdf->Image($signatureFile, 62, $pdf->GetY() - 6, 60, 0, 'PNG');
            @unlink($signatureFile);
            $pdf->Ln(24);
        } else {
            $pdf->Cell(0, 6, '___________________________', 0, 1);
            $pdf->Ln(8);
        }

        $pdf->Cell(0, 6, $this->encode('Ort: ' . $vars['ort'] . '    Datum: ' . $vars['datum']), 0, 1);

        // Bearbeitungsinformationen
        $pdf->SetY(-48);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->MultiCell(0, 5, $this->encode($this->settings->getString('completion.footer_title', self::DEFAULT_FOOTER_TITLE)));
        $pdf->SetFont('Helvetica', '', 9);
        $footer = $this->settings->getString('completion.footer_template', self::DEFAULT_FOOTER);
        $pdf->MultiCell(0, 5, $this->encode($this->renderTemplate($footer, $vars)));
    }

    /** Zeichnet eine Zeile mit Kontrollkästchen (angekreuzt oder leer). */
    private function checkboxLine(Fpdi $pdf, string $text, bool $checked): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->Rect($x, $y + 1, 4, 4);
        if ($checked) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Text($x + 0.8, $y + 4.4, 'X');
            $pdf->SetFont('Helvetica', '', 11);
        }
        $pdf->SetX($x + 7);
        $pdf->MultiCell(0, 6, $this->encode($text));
    }

    /** Schreibt die Signatur (Data-URL, PNG) in eine temporäre Datei. */
    private function signatureImageFile(string $signatureData): ?string
    {
        if (!preg_match('#^data:image/png;base64,(.+)$#', $signatureData, $m)) {
            return null;
        }

        $binary = base64_decode($m[1], true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $base = tempnam(sys_get_temp_dir(), 'patsign_sig_');
        if ($base === false) {
            return null;
        }
        $file = $base . '.png';
        @unlink($base);
        if (file_put_contents($file, $binary) === false) {
            return null;
        }

        return $file;
    }

    /** FPDF erwartet Windows-1252-kodierte Texte. */
    private function encode(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
}
