<?php
/**
 * Admin-Layout: umschließt Sektionsinhalt mit Admin-Navigation und Breadcrumbs.
 * Erwartet: $adminContent, $section, $sections, $sectionTitle, $csrf, $user, $flash
 */
require_once __DIR__ . '/../partials/helpers.php';

ob_start();
?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumbs">
        <li><a href="/dashboard">Dashboard</a></li>
        <li><a href="/admin">Administration</a></li>
        <li><span aria-current="page"><?= e($sectionTitle) ?></span></li>
    </ol>
</nav>

<?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="alert"
         data-flash="<?= e($flash['message']) ?>" data-flash-type="<?= e($flash['type']) ?>">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>

<div class="admin-layout">
    <nav class="admin-nav" aria-label="Administrationsbereiche">
        <?php foreach ($sections as $key => $label): ?>
            <a href="/admin/<?= e($key) ?>" class="<?= $key === $section ? 'is-active' : '' ?>" <?= $key === $section ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="admin-content">
        <h1 class="page-title"><?= e($sectionTitle) ?></h1>
        <p class="page-subtitle"><?= e($sectionSubtitle ?? 'Systemeinstellungen zentral verwalten') ?></p>
        <?= $adminContent ?? '' ?>
    </div>
</div>
<?php
$innerContent = ob_get_clean();
$title = $sectionTitle . ' – Administration – PatSign';
$activeNav = 'admin';
$areaLabel = 'Administration';
$scripts = ['/js/admin.js'];
include __DIR__ . '/../partials/staff_layout.php';
