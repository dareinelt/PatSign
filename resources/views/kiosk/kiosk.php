<?php
require_once __DIR__ . '/../partials/helpers.php';
ob_start();
?>
<div class="kiosk-shell" id="kiosk" data-csrf="<?= e($csrf) ?>" data-device-status="<?= e($deviceStatus) ?>" data-freehand="<?= !empty($freehandEnabled) ? '1' : '0' ?>">
    <header class="patient-header">
        <span class="clinic-name"><?= e($clinicName) ?></span>
        <div class="topbar-spacer"></div>
        <span class="kiosk-device-name" aria-label="Gerätename"><?= e($deviceName) ?></span>
    </header>

    <!-- Zustand 1: Warten auf Zuweisung -->
    <main class="kiosk-main kiosk-state is-active" id="kiosk-waiting" aria-label="Wartezustand">
        <div class="kiosk-status">
            <span class="kiosk-status-spinner" aria-hidden="true"></span>
            <h1>Bitte warten…</h1>
            <p class="wizard-text mb-0">Dieses Gerät ist bereit. Das Personal weist Ihnen gleich Ihre Dokumente zu.</p>
        </div>
    </main>

    <!-- Gesperrt / außer Betrieb -->
    <main class="kiosk-main kiosk-state" id="kiosk-blocked" aria-label="Gerät gesperrt">
        <div class="kiosk-status">
            <h1>Gerät nicht verfügbar</h1>
            <p class="wizard-text mb-0" id="kiosk-blocked-text">Dieses Gerät wurde gesperrt. Bitte wenden Sie sich an das Personal.</p>
        </div>
    </main>

    <!-- Zustand 2: Patientenmappe (Signaturassistent) -->
    <main class="kiosk-main kiosk-state" id="kiosk-wizard" aria-label="Signaturassistent">
        <div class="wizard-progress">
            <div class="wizard-step-label">
                <span id="wizard-progress-label">Schritt 1</span>
                <span id="kiosk-patient-label"></span>
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
                <h1 id="kiosk-welcome">Herzlich willkommen</h1>
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
            <ul class="doc-list" id="kiosk-doc-list"></ul>
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
                    <button type="button" class="btn btn-secondary hidden" id="ink-undo" aria-label="Letzte Stifteingabe rückgängig machen" disabled>
                        <svg class="icon" aria-hidden="true"><use href="#icon-undo"/></svg>
                        Rückgängig
                    </button>
                    <span class="text-muted text-sm" id="doc-counter"></span>
                </div>
                <div class="doc-viewer-body">
                    <div class="doc-viewer-frame" id="doc-frame" role="document" aria-label="Dokumentanzeige mit Blättern und Zoom"></div>
                    <div class="doc-scroll-controls" aria-label="Im Dokument scrollen">
                        <button type="button" class="doc-scroll-btn" id="doc-scroll-up" aria-label="Nach oben scrollen">
                            <svg class="icon" aria-hidden="true"><use href="#icon-chevron-up"/></svg>
                        </button>
                        <button type="button" class="doc-scroll-btn" id="doc-scroll-down" aria-label="Nach unten scrollen">
                            <svg class="icon" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-progress hidden" id="form-progress" aria-live="polite">
                <span id="form-progress-label"></span>
                <span class="form-progress-bar"><span class="form-progress-fill" id="form-progress-fill"></span></span>
            </div>
            <p class="text-muted text-sm text-center mt-2 mb-0">
                <?php if (!empty($freehandEnabled)): ?>
                    Mit dem Stift können Sie direkt auf dem Dokument schreiben. Zum Blättern die Pfeiltasten nutzen, mit dem Finger wischen oder scrollen.
                <?php else: ?>
                    Zum Vergrößern zwei Finger auseinanderziehen, zum Blättern wischen oder scrollen.
                <?php endif; ?>
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

        <!-- Schritt 4: Bestätigung & E-Mail -->
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
                <div class="consent-notice" id="email-consent-notice" tabindex="0" aria-label="Datenschutzhinweis zum E-Mail-Versand">
                    <?= nl2br(e($emailConsentNotice ?? '')) ?>
                </div>
                <div class="form-group hidden" id="email-field">
                    <label for="patient-email">Ihre E-Mail-Adresse</label>
                    <input type="email" id="patient-email" inputmode="email" autocomplete="email" placeholder="name@beispiel.de">
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

        <!-- Schritt 5: Unterschrift (Apple Pencil / Finger) -->
        <section class="wizard-step" aria-label="Unterschrift">
            <h2 class="wizard-title">Ihre Unterschrift</h2>
            <p class="wizard-text">Bitte unterschreiben Sie im Feld unten – mit dem Finger oder dem Apple Pencil.</p>
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
    </main>

    <!-- Zustand 3: Danke -->
    <main class="kiosk-main kiosk-state" id="kiosk-done" aria-label="Fertig">
        <div class="wizard-hero is-success kiosk-status">
            <span class="hero-icon"><svg class="icon" aria-hidden="true"><use href="#icon-check"/></svg></span>
            <h1>Vielen Dank!</h1>
            <p class="wizard-text mb-0">Ihre Dokumente wurden erfolgreich unterschrieben.</p>
            <p class="wizard-text mb-0 hidden" id="finish-email-note">Eine Kopie wird Ihnen per E-Mail zugesendet.</p>
            <p class="wizard-text">Sie können das Gerät nun an unser Personal zurückgeben.</p>
        </div>
    </main>
</div>
<?php
$content = ob_get_clean();
$title = 'Signaturgerät – ' . ($clinicName ?? 'PatSign');
$scripts = ['/js/pdf-viewer.js', '/js/form-overlay.js', '/js/ink-overlay.js', '/js/kiosk.js'];
include __DIR__ . '/../partials/layout.php';
