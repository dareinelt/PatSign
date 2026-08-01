<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Automatischer Dokumentenimport aus dem Importpfad';
ob_start();
?>
<div class="card">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="import">
        <div class="form-group">
            <label for="import-path">Importpfad</label>
            <input id="import-path" name="import_path" value="<?= e($settings->getString('app.import_watch_path')) ?>">
            <span class="form-hint">Ordner, der auf neue PDF-Dokumente überwacht wird.</span>
        </div>
        <div class="form-group">
            <label for="polling-interval">Pollingintervall (Sekunden)</label>
            <input id="polling-interval" type="number" min="5" max="3600" name="polling_interval" value="<?= e($settings->getString('import.polling_interval', '30')) ?>">
        </div>
        <label class="checkbox-field">
            <input type="checkbox" name="auto_import" value="1" <?= $settings->getBool('import.auto_import') ? 'checked' : '' ?>>
            <span>Automatischer Import aktiviert</span>
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
