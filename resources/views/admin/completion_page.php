<?php
require_once __DIR__ . '/../partials/helpers.php';

use App\Services\CompletionPageService;

$sectionSubtitle = 'Inhalt der Abschlussseite, die an jedes unterschriebene Dokument angehängt wird';
ob_start();
?>
<div class="card">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="completion-page">
        <div class="form-group">
            <label for="header-template">Kopfblock (Patientendaten)</label>
            <textarea id="header-template" name="header_template" rows="5"><?= e($settings->getString('completion.header_template', CompletionPageService::DEFAULT_HEADER)) ?></textarea>
        </div>
        <div class="form-group">
            <label for="body-template">Einleitungstext</label>
            <textarea id="body-template" name="body_template" rows="5"><?= e($settings->getString('completion.body_template', CompletionPageService::DEFAULT_BODY)) ?></textarea>
        </div>
        <div class="form-group">
            <label for="email-section-title">Überschrift E-Mail-Versand</label>
            <input id="email-section-title" name="email_section_title" value="<?= e($settings->getString('completion.email_section_title', CompletionPageService::DEFAULT_EMAIL_SECTION_TITLE)) ?>">
        </div>
        <div class="form-group">
            <label for="email-consent-template">Option: Versand gewünscht</label>
            <textarea id="email-consent-template" name="email_consent_template" rows="2"><?= e($settings->getString('completion.email_consent_template', CompletionPageService::DEFAULT_EMAIL_CONSENT)) ?></textarea>
            <span class="form-hint">Wird als Kontrollkästchen dargestellt; angekreuzt, wenn der Patient den Versand wünscht.</span>
        </div>
        <div class="form-group">
            <label for="email-no-consent-template">Option: kein Versand</label>
            <textarea id="email-no-consent-template" name="email_no_consent_template" rows="2"><?= e($settings->getString('completion.email_no_consent_template', CompletionPageService::DEFAULT_EMAIL_NO_CONSENT)) ?></textarea>
            <span class="form-hint">Wird als Kontrollkästchen dargestellt; angekreuzt, wenn kein Versand gewünscht ist.</span>
        </div>
        <div class="form-group">
            <label for="confirmation-title">Überschrift Bestätigung</label>
            <input id="confirmation-title" name="confirmation_title" value="<?= e($settings->getString('completion.confirmation_title', CompletionPageService::DEFAULT_CONFIRMATION_TITLE)) ?>">
        </div>
        <div class="form-group">
            <label for="signed-statement">Bestätigungssatz</label>
            <textarea id="signed-statement" name="signed_statement" rows="2"><?= e($settings->getString('completion.signed_statement', CompletionPageService::DEFAULT_SIGNED_STATEMENT)) ?></textarea>
        </div>
        <div class="form-group">
            <label for="footer-title">Überschrift Bearbeitungsinformationen</label>
            <input id="footer-title" name="footer_title" value="<?= e($settings->getString('completion.footer_title', CompletionPageService::DEFAULT_FOOTER_TITLE)) ?>">
        </div>
        <div class="form-group">
            <label for="footer-template">Bearbeitungsinformationen</label>
            <textarea id="footer-template" name="footer_template" rows="6"><?= e($settings->getString('completion.footer_template', CompletionPageService::DEFAULT_FOOTER)) ?></textarea>
        </div>
        <p class="form-hint">
            Verfügbare Platzhalter:
            <code>{nachname}</code>, <code>{vorname}</code>, <code>{geburtsdatum}</code>, <code>{fallnummer}</code>,
            <code>{dokumententyp}</code>, <code>{dateiname}</code>, <code>{klinik}</code>, <code>{ort}</code>,
            <code>{datum}</code>, <code>{uhrzeit}</code>, <code>{email}</code>, <code>{bearbeiter}</code>,
            <code>{beginn}</code>, <code>{document_id}</code>, <code>{status}</code>, <code>{geraet}</code>.
            Alle Platzhalter werden bei der Ausgabe befüllt. Klinikname und Ort werden im Bereich „Allgemein“ gepflegt.
            Die digitale Unterschrift des Patienten sowie Ort und aktuelles Datum werden automatisch
            unterhalb des Bestätigungssatzes eingefügt.
        </p>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
