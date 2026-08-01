<?php
require_once __DIR__ . '/../partials/helpers.php';
ob_start();
?>
<div class="kiosk-shell" id="kiosk-register" data-csrf="<?= e($csrf) ?>">
    <header class="patient-header">
        <span class="clinic-name"><?= e($clinicName) ?></span>
    </header>
    <main class="kiosk-main is-active" aria-label="Geräteregistrierung">
        <div class="kiosk-card">
            <div class="alert alert-error hidden" id="register-unsupported" role="alert">
                Dieses Gerät wurde nicht als unterstütztes Tablet erkannt (z.&nbsp;B. iPad mit Safari oder Chrome).
                Die Registrierung ist dennoch möglich, wird aber nicht empfohlen.
            </div>
            <h1>Gerät registrieren</h1>
            <p class="wizard-text">
                Dieses Gerät ist noch nicht als Signaturgerät registriert.
                Bitte vergeben Sie einen eindeutigen Gerätenamen, zum Beispiel
                „Anmeldung 1“, „OP 1“, „Station A“ oder „Ambulanz 3“.
            </p>
            <div class="alert alert-error hidden" id="register-error" role="alert"></div>
            <form id="register-form">
                <div class="form-group">
                    <label for="register-name">Gerätename</label>
                    <input id="register-name" name="name" maxlength="120" required autocomplete="off" placeholder="z. B. Anmeldung 1">
                    <span class="form-hint">Der Name muss eindeutig sein und wird serverseitig geprüft.</span>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block" id="register-submit">Gerät registrieren</button>
            </form>
            <p class="text-muted text-sm text-center mt-2 mb-0">
                <a href="/login" id="register-staff-link">Zur Personal-Anmeldung</a>
            </p>
        </div>
    </main>
</div>
<?php
$content = ob_get_clean();
$title = 'Geräteregistrierung – ' . ($clinicName ?? 'PatSign');
$scripts = ['/js/kiosk.js'];
include __DIR__ . '/../partials/layout.php';
