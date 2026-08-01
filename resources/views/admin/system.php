<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Wartung, Debug, Sprache und Zeitzone';
ob_start();
?>
<div class="card">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="system">
        <div class="stack mb-4">
            <label class="checkbox-field">
                <input type="checkbox" name="maintenance_mode" value="1" <?= $settings->getBool('system.maintenance_mode') ? 'checked' : '' ?>>
                <span>Wartungsmodus aktivieren</span>
            </label>
            <label class="checkbox-field">
                <input type="checkbox" name="debug_mode" value="1" <?= $settings->getBool('system.debug_mode') ? 'checked' : '' ?>>
                <span>Debugmodus aktivieren</span>
            </label>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="system-language">Sprache</label>
                <select id="system-language" name="language">
                    <?php $language = $settings->getString('system.language', 'de'); ?>
                    <option value="de" <?= $language === 'de' ? 'selected' : '' ?>>Deutsch</option>
                    <option value="en" <?= $language === 'en' ? 'selected' : '' ?>>Englisch</option>
                </select>
            </div>
            <div class="form-group">
                <label for="system-timezone">Zeitzone</label>
                <select id="system-timezone" name="timezone">
                    <?php $timezone = $settings->getString('system.timezone', 'Europe/Berlin'); ?>
                    <?php foreach (['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'UTC'] as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $timezone === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
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
