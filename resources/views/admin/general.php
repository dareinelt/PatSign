<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Klinikname, Logo und Standardfarben';
ob_start();
?>
<div class="card">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="general">
        <div class="form-group">
            <label for="clinic-name">Klinikname</label>
            <input id="clinic-name" name="clinic_name" value="<?= e($settings->getString('general.clinic_name', 'PatSign')) ?>" required>
        </div>
        <div class="form-group">
            <label for="logo-text">Logo-Kürzel</label>
            <input id="logo-text" name="logo_text" maxlength="3" value="<?= e($settings->getString('general.logo_text', 'PS')) ?>">
            <span class="form-hint">Kurzes Kürzel (max. 3 Zeichen), das als Logo angezeigt wird.</span>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="primary-color">Primärfarbe</label>
                <input id="primary-color" type="color" name="primary_color" value="<?= e($settings->getString('general.primary_color', '#0f6cbd')) ?>">
            </div>
            <div class="form-group">
                <label for="accent-color">Akzentfarbe</label>
                <input id="accent-color" type="color" name="accent_color" value="<?= e($settings->getString('general.accent_color', '#0e8276')) ?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
