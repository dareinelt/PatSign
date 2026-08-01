<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Ablage signierter Dokumente';
ob_start();
?>
<div class="card" data-share-settings data-csrf="<?= e($csrf) ?>">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="export">
        <div class="form-group">
            <label for="export-path">Exportpfad (Netzwerkpfad)</label>
            <input id="export-path" name="network_share" placeholder="\\Server\Freigabeverzeichnis" value="<?= e($settings->getString('app.network_share_path')) ?>">
            <span class="form-hint">UNC-Pfad (\\Server\Freigabe) oder lokales Verzeichnis als Ziel für signierte PDF-Dokumente.</span>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="export-domain">Domäne (optional)</label>
                <input id="export-domain" name="export_domain" value="<?= e($settings->getString('export.share_domain')) ?>">
            </div>
            <div class="form-group">
                <label for="export-username">Benutzer</label>
                <input id="export-username" name="export_username" value="<?= e($settings->getString('export.share_username')) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="export-password">Passwort</label>
                <input id="export-password" type="password" name="export_password" value="<?= e($settings->getString('export.share_password')) ?>" autocomplete="new-password">
            </div>
        </div>
        <div class="form-group">
            <button type="button" class="btn" data-share-test="export">Zugriff testen</button>
            <span class="text-sm" data-share-test-status="export"></span>
        </div>
        <div class="form-group">
            <label for="file-naming">Dateibenennung</label>
            <input id="file-naming" name="file_naming" value="<?= e($settings->getString('export.file_naming', '{fallnummer}_{nachname}{vorname}_{geburtsdatum}_{dokumenttyp}')) ?>">
            <span class="form-hint">Platzhalter: {fallnummer}, {nachname}, {vorname}, {geburtsdatum}, {dokumenttyp}</span>
        </div>
        <label class="checkbox-field">
            <input type="checkbox" name="pdfa_enabled" value="1" <?= $settings->getBool('export.pdfa_enabled') ? 'checked' : '' ?>>
            <span>PDF/A aktivieren</span>
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
