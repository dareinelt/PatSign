<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Mailversand konfigurieren und testen';
ob_start();
?>
<div class="stack">
    <div class="card">
        <form method="post" action="/admin/settings">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="section" value="smtp">
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp-host">Host</label>
                    <input id="smtp-host" name="host" value="<?= e($settings->getString('mail.host')) ?>">
                </div>
                <div class="form-group">
                    <label for="smtp-port">Port</label>
                    <input id="smtp-port" type="number" min="1" max="65535" name="port" value="<?= e($settings->getString('mail.port')) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp-username">Benutzername</label>
                    <input id="smtp-username" name="username" value="<?= e($settings->getString('mail.username')) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="smtp-password">Passwort</label>
                    <input id="smtp-password" type="password" name="password" value="<?= e($settings->getString('mail.password')) ?>" autocomplete="new-password">
                </div>
            </div>
            <div class="form-group">
                <label for="smtp-encryption">Verschlüsselung</label>
                <select id="smtp-encryption" name="encryption">
                    <?php $encryption = $settings->getString('mail.encryption'); ?>
                    <option value="" <?= $encryption === '' ? 'selected' : '' ?>>Keine</option>
                    <option value="tls" <?= $encryption === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                    <option value="ssl" <?= $encryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp-from">Absenderadresse</label>
                    <input id="smtp-from" type="email" name="from" value="<?= e($settings->getString('mail.from')) ?>">
                </div>
                <div class="form-group">
                    <label for="smtp-from-name">Absendername</label>
                    <input id="smtp-from-name" name="from_name" value="<?= e($settings->getString('mail.from_name')) ?>">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Testmail senden</h2></div>
        <form method="post" action="/admin/smtp/test">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="test-mail-to">Empfängeradresse</label>
                <input id="test-mail-to" type="email" name="to" placeholder="empfaenger@beispiel.de" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">
                    <svg class="icon" aria-hidden="true"><use href="#icon-mail"/></svg>
                    Testmail senden
                </button>
            </div>
        </form>
    </div>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
