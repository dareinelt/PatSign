<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Klinikname, Ort, Logo und Standardfarben';
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
            <label for="clinic-location">Ort</label>
            <input id="clinic-location" name="clinic_location" value="<?= e($settings->getString('general.clinic_location', '')) ?>">
            <span class="form-hint">Ort des Klinikums – erscheint u. a. auf der Abschlussseite unterschriebener Dokumente.</span>
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
        <div class="form-group">
            <label for="folder-overview-hours">Zeitraum Mappenübersicht (Stunden)</label>
            <input id="folder-overview-hours" type="number" name="folder_overview_hours" min="1" max="720" value="<?= e($settings->getString('dashboard.folder_overview_hours', '24')) ?>">
            <span class="form-hint">Zeitraum, in dem Patientenmappen mit offenen Unterschriften in der Übersicht „Patientenmappe öffnen“ angezeigt werden (Standard: 24 Stunden).</span>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
