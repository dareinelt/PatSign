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
    public const DEFAULT_HEADER = '{nachname}, {vorname}, {geburtsdatum}, Fallnummer {fallnummer}, {dokumententyp}, {dateiname}';
    public const DEFAULT_BODY = 'Der Patient ({nachname}, {vorname} ({geburtsdatum})) war am {datum} Patient im {klinik} und hat folgendes Dokument ({dokumententyp}, {dateiname}) am {datum} um {uhrzeit} Uhr zur digitalen Unterschrift vorgelegt bekommen.';
    public const DEFAULT_EMAIL_CONSENT = 'Der Patient wünscht eine Zustellung einer digitalen Kopie des Dokuments per E-Mail an {email}.';
    public const DEFAULT_EMAIL_NO_CONSENT = 'Der Patient wünscht keine digitale Zustellung einer digitalen Kopie des Dokuments per E-Mail.';
    public const DEFAULT_SIGNED_STATEMENT = 'Der Patient hat das oben genannte Dokument digital unterschrieben.';
    public const DEFAULT_FOOTER = 'Vorgang bearbeitet durch {bearbeiter}, begonnen um {beginn} Uhr';

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
     *                                      operator, started_at, signed_at, final_name
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
        $originalPath = (string) ($document['original_path'] ?? '');
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

        return [
            'nachname' => (string) ($document['last_name'] ?? ''),
            'vorname' => (string) ($document['first_name'] ?? ''),
            'geburtsdatum' => $birthDate,
            'fallnummer' => (string) ($document['case_number'] ?? ''),
            'dokumententyp' => (string) ($document['document_type'] ?? 'Unbekannt'),
            'dateiname' => (string) ($context['final_name'] ?? ''),
            'klinik' => $this->settings->getString('general.clinic_name', 'PatSign'),
            'ort' => $this->settings->getString('general.clinic_location', ''),
            'datum' => date('d.m.Y', $signedAt),
            'uhrzeit' => date('H:i', $signedAt),
            'email' => (string) ($context['email'] ?? ''),
            'bearbeiter' => (string) ($context['operator'] ?? ''),
            'beginn' => date('H:i', $startedAt),
        ];
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

        // Kopfzeile mit Patientendaten
        $pdf->SetY(15);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $header = $this->settings->getString('completion.header_template', self::DEFAULT_HEADER);
        $pdf->MultiCell(0, 5, $this->encode($this->renderTemplate($header, $vars)));

        // Fließtext
        $pdf->SetY(50);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $body = $this->settings->getString('completion.body_template', self::DEFAULT_BODY);
        $pdf->MultiCell(0, 6, $this->encode($this->renderTemplate($body, $vars)));
        $pdf->Ln(6);

        $emailTemplate = $emailConsent
            ? $this->settings->getString('completion.email_consent_template', self::DEFAULT_EMAIL_CONSENT)
            : $this->settings->getString('completion.email_no_consent_template', self::DEFAULT_EMAIL_NO_CONSENT);
        $pdf->MultiCell(0, 6, $this->encode($this->renderTemplate($emailTemplate, $vars)));
        $pdf->Ln(6);

        $statement = $this->settings->getString('completion.signed_statement', self::DEFAULT_SIGNED_STATEMENT);
        $pdf->MultiCell(0, 6, $this->encode($this->renderTemplate($statement, $vars)));
        $pdf->Ln(12);

        // Digitale Unterschrift aus dem Prozess
        $signatureFile = $this->signatureImageFile($signatureData);
        if ($signatureFile !== null) {
            $pdf->Image($signatureFile, 20, $pdf->GetY(), 70, 0, 'PNG');
            @unlink($signatureFile);
            $pdf->Ln(35);
        } else {
            $pdf->Ln(25);
        }

        $pdf->Cell(90, 6, str_repeat('_', 55), 0, 1);
        $pdf->Cell(0, 6, $this->encode(trim($vars['ort'] . ', ' . $vars['datum'], ', ')), 0, 1);

        // Fußzeile
        $pdf->SetY(-25);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $footer = $this->settings->getString('completion.footer_template', self::DEFAULT_FOOTER);
        $pdf->MultiCell(0, 5, $this->encode($this->renderTemplate($footer, $vars)));
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
