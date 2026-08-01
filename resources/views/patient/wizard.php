<?php
require_once __DIR__ . '/../partials/helpers.php';

$documentsJson = json_encode(array_map(static fn (array $d): array => [
    'id' => (int) $d['id'],
    'document_type' => (string) ($d['document_type'] ?? 'Dokument'),
    'has_form' => (bool) ($d['has_form'] ?? false),
], $documents ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

ob_start();
?>
<div class="patient-shell" id="patient-wizard" data-csrf="<?= e($csrf) ?>" data-documents="<?= e($documentsJson) ?>">
    <header class="patient-header">
        <span class="clinic-name"><?= e($clinicName) ?></span>
        <div class="topbar-spacer"></div>
        <form method="post" action="/patient/exit">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="btn btn-ghost btn-sm">Vorgang beenden</button>
        </form>
    </header>

    <main class="patient-main" aria-label="Signaturassistent">
        <div class="wizard-progress">
            <div class="wizard-step-label">
                <span id="wizard-progress-label">Schritt 1</span>
                <span><?= e($patientName ?: 'Patient') ?> · Fall <?= e($caseNumber) ?></span>
            </div>
            <div class="progress" role="progressbar" aria-label="Fortschritt" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="progress-bar" id="wizard-progress-bar"></div>
            </div>
        </div>
        <p class="visually-hidden" id="wizard-live" aria-live="polite"></p>

        <!-- Schritt 1: Begrüßung -->
        <section class="wizard-step is-active" aria-label="Begrüßung">
            <div class="wizard-hero">
                <span class="hero-icon"><svg class="icon" aria-hidden="true"><use href="#icon-heart"/></svg></span>
                <h1>Herzlich willkommen<?= $patientName !== '' ? ', ' . e($patientName) : '' ?></h1>
                <p class="wizard-text mb-0">
                    Wir führen Sie nun Schritt für Schritt durch Ihre Dokumente.
                    Bitte lesen Sie alles in Ruhe durch. Am Ende unterschreiben Sie einmal für alle Dokumente.
                </p>
            </div>
            <div class="wizard-nav">
                <span></span>
                <button type="button" class="btn btn-primary btn-lg" data-wizard-next>
                    Los geht's
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-right"/></svg>
                </button>
            </div>
        </section>

        <!-- Schritt 2: Dokumentübersicht -->
        <section class="wizard-step" aria-label="Dokumentübersicht">
            <h2 class="wizard-title">Ihre Dokumente</h2>
            <p class="wizard-text">Folgende Dokumente liegen heute für Sie bereit:</p>
            <ul class="doc-list">
                <?php foreach ($documents as $index => $document): ?>
                    <li>
                        <span class="doc-icon"><svg class="icon" aria-hidden="true"><use href="#icon-document"/></svg></span>
                        <div>
                            <div class="doc-title"><?= e($document['document_type'] ?? 'Dokument') ?></div>
                            <div class="text-muted text-sm">Dokument <?= $index + 1 ?> von <?= count($documents) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="wizard-nav">
                <button type="button" class="btn btn-secondary btn-lg" data-wizard-prev>
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-left"/></svg>
                    Zurück
                </button>
                <button type="button" class="btn btn-primary btn-lg" data-open-documents>
                    Dokumente ansehen
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-right"/></svg>
                </button>
            </div>
        </section>

        <!-- Schritt 3: Dokumentanzeige -->
        <section class="wizard-step" aria-label="Dokument lesen">
            <div class="doc-viewer">
                <div class="doc-viewer-toolbar">
                    <span class="doc-viewer-title" id="doc-viewer-title">Dokument</span>
                    <span class="text-muted text-sm" id="doc-counter"></span>
                </div>
                <div class="doc-viewer-frame" id="doc-frame" role="document" aria-label="Dokumentanzeige mit Blättern und Zoom"></div>
            </div>
            <div class="form-progress hidden" id="form-progress" aria-live="polite">
                <span id="form-progress-label"></span>
                <span class="form-progress-bar"><span class="form-progress-fill" id="form-progress-fill"></span></span>
            </div>
            <p class="text-muted text-sm text-center mt-2 mb-0">
                Zum Vergrößern zwei Finger auseinanderziehen, zum Blättern wischen oder scrollen.
            </p>
            <div class="wizard-nav">
                <button type="button" class="btn btn-secondary btn-lg" id="doc-prev">
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-left"/></svg>
                    Vorheriges Dokument
                </button>
                <button type="button" class="btn btn-primary btn-lg" id="doc-next">
                    Nächstes Dokument
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-right"/></svg>
                </button>
            </div>
        </section>

        <!-- Schritt 4: Datenschutz & E-Mail-Zustimmung -->
        <section class="wizard-step" aria-label="Bestätigung und E-Mail">
            <h2 class="wizard-title">Bestätigung</h2>
            <p class="wizard-text">Bitte bestätigen Sie die folgenden Punkte:</p>
            <div class="stack wizard-narrow">
                <label class="checkbox-field">
                    <input type="checkbox" id="read-confirmed">
                    <span>Ich bestätige, dass ich alle Dokumente gelesen habe.</span>
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" id="email-consent">
                    <span>Ich möchte meine Dokumente per E-Mail erhalten. <span class="text-muted">(optional)</span></span>
                </label>
                <div class="form-group hidden" id="email-field">
                    <label for="patient-email">Ihre E-Mail-Adresse</label>
                    <input type="email" id="patient-email" inputmode="email" autocomplete="email" placeholder="name@beispiel.de">
                    <span class="form-hint">Bitte prüfen oder ergänzen Sie Ihre E-Mail-Adresse.</span>
                </div>
            </div>
            <div class="wizard-nav">
                <button type="button" class="btn btn-secondary btn-lg" data-wizard-prev>
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-left"/></svg>
                    Zurück
                </button>
                <button type="button" class="btn btn-primary btn-lg" data-wizard-next>
                    Weiter zur Unterschrift
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-right"/></svg>
                </button>
            </div>
        </section>

        <!-- Schritt 5: Unterschrift -->
        <section class="wizard-step" aria-label="Unterschrift">
            <h2 class="wizard-title">Ihre Unterschrift</h2>
            <p class="wizard-text">Bitte unterschreiben Sie im Feld unten – mit dem Finger oder einem Stift.</p>
            <div class="alert alert-error hidden" id="signature-error" role="alert"></div>
            <div class="signature-pad-wrapper">
                <canvas id="signature-pad" aria-label="Unterschriftsfeld"></canvas>
                <span class="signature-hint">Hier unterschreiben</span>
            </div>
            <div class="cluster justify-center">
                <button type="button" class="btn btn-secondary" id="signature-clear">Unterschrift löschen</button>
            </div>
            <div class="wizard-nav">
                <button type="button" class="btn btn-secondary btn-lg" data-wizard-prev>
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-left"/></svg>
                    Zurück
                </button>
                <button type="button" class="btn btn-primary btn-lg" id="signature-submit">Unterschrift bestätigen</button>
            </div>
        </section>

        <!-- Schritt 6: Zusammenfassung -->
        <section class="wizard-step" aria-label="Zusammenfassung">
            <h2 class="wizard-title">Zusammenfassung</h2>
            <ul class="summary-list wizard-narrow">
                <li>
                    <span class="summary-label">Name</span>
                    <span class="summary-value"><?= e($patientName ?: 'Unbekannt') ?></span>
                </li>
                <li>
                    <span class="summary-label">Fallnummer</span>
                    <span class="summary-value"><?= e($caseNumber) ?></span>
                </li>
                <li>
                    <span class="summary-label">Dokumente</span>
                    <span class="summary-value" id="summary-doc-count"><?= count($documents) ?> Dokument(e)</span>
                </li>
                <li>
                    <span class="summary-label">E-Mail-Versand</span>
                    <span class="summary-value" id="summary-email">–</span>
                </li>
            </ul>
            <div class="wizard-nav">
                <span></span>
                <button type="button" class="btn btn-primary btn-lg" data-wizard-next>
                    Abschließen
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron-right"/></svg>
                </button>
            </div>
        </section>

        <!-- Schritt 7: Fertig -->
        <section class="wizard-step" aria-label="Fertig">
            <div class="wizard-hero is-success">
                <span class="hero-icon"><svg class="icon" aria-hidden="true"><use href="#icon-check"/></svg></span>
                <h1>Vielen Dank!</h1>
                <p class="wizard-text mb-0">Ihre Dokumente wurden erfolgreich unterschrieben.</p>
                <p class="wizard-text mb-0 hidden" id="finish-email-note">Eine Kopie wird Ihnen per E-Mail zugesendet.</p>
                <p class="wizard-text">Sie können das Gerät nun an unser Personal zurückgeben.</p>
                <form method="post" action="/patient/exit">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn btn-primary btn-lg">Vorgang beenden</button>
                </form>
            </div>
        </section>
    </main>
</div>
<?php
$content = ob_get_clean();
$title = 'Ihre Dokumente – ' . ($clinicName ?? 'PatSign');
$scripts = ['/js/pdf-viewer.js', '/js/form-overlay.js', '/js/patient.js'];
include __DIR__ . '/../partials/layout.php';
