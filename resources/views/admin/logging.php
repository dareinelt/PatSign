<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Protokollierung konfigurieren';
ob_start();
?>
<div class="card">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="logging">
        <div class="form-group">
            <label for="log-level">Log-Level</label>
            <select id="log-level" name="log_level">
                <?php $level = $settings->getString('logging.level', 'info'); ?>
                <?php foreach (['debug', 'info', 'warning', 'error'] as $option): ?>
                    <option value="<?= e($option) ?>" <?= $level === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="retention-days">Aufbewahrung (Tage)</label>
            <input id="retention-days" type="number" min="1" max="3650" name="retention_days" value="<?= e($settings->getString('logging.retention_days', '90')) ?>">
        </div>
        <div class="form-group">
            <label for="log-path">Speicherort</label>
            <input id="log-path" name="log_path" value="<?= e($settings->getString('logging.path', 'logs/')) ?>">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
