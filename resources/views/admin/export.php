<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Ablage signierter Dokumente';
ob_start();
?>
<div class="card">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="export">
        <div class="form-group">
            <label for="network-share">Netzwerk-Share</label>
            <input id="network-share" name="network_share" value="<?= e($settings->getString('app.network_share_path')) ?>">
            <span class="form-hint">Zielpfad für signierte PDF-Dokumente.</span>
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
