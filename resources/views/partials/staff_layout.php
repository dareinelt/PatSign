<?php
/**
 * Personal-Layout: Sidebar + Topbar.
 * Erwartet: $content, $title, $activeNav, $user, optional $areaLabel, $scripts, $clinicName
 */
require_once __DIR__ . '/helpers.php';

$user = $user ?? [];
$isAdmin = ($user['role'] ?? '') === 'admin';
$activeNav = $activeNav ?? '';
$areaLabel = $areaLabel ?? 'Personalbereich';
$clinicName = $clinicName ?? 'PatSign';

ob_start();
?>
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<div class="app-shell">
    <aside class="sidebar">
        <a class="sidebar-brand" href="/dashboard">
            <span class="brand-logo" aria-hidden="true"><?= e(mb_substr($clinicName, 0, 2)) ?></span>
            <span><?= e($clinicName) ?></span>
        </a>
        <nav class="sidebar-nav" aria-label="Hauptnavigation">
            <a href="/dashboard" class="<?= $activeNav === 'dashboard' ? 'is-active' : '' ?>" <?= $activeNav === 'dashboard' ? 'aria-current="page"' : '' ?>>
                <svg class="icon" aria-hidden="true"><use href="#icon-home"/></svg>
                Dashboard
            </a>
            <a href="/documents" class="<?= $activeNav === 'documents' ? 'is-active' : '' ?>" <?= $activeNav === 'documents' ? 'aria-current="page"' : '' ?>>
                <svg class="icon" aria-hidden="true"><use href="#icon-document"/></svg>
                Dokumente
            </a>
            <?php if ($isAdmin): ?>
                <span class="sidebar-section-label">Verwaltung</span>
                <a href="/admin" class="<?= $activeNav === 'admin' ? 'is-active' : '' ?>" <?= $activeNav === 'admin' ? 'aria-current="page"' : '' ?>>
                    <svg class="icon" aria-hidden="true"><use href="#icon-settings"/></svg>
                    Administration
                </a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <form method="post" action="/logout">
                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                <button type="submit" class="btn btn-ghost btn-block">
                    <svg class="icon" aria-hidden="true"><use href="#icon-logout"/></svg>
                    Abmelden
                </button>
            </form>
        </div>
    </aside>
    <header class="topbar">
        <span class="topbar-area-label <?= $activeNav === 'admin' ? 'is-admin' : '' ?>"><?= e($areaLabel) ?></span>
        <div class="topbar-spacer"></div>
        <div class="notification-center" id="notification-center" data-csrf="<?= e($csrf ?? '') ?>">
            <button type="button" class="notification-bell" id="notification-bell" aria-haspopup="true" aria-expanded="false" aria-label="Benachrichtigungen">
                <svg class="icon" aria-hidden="true"><use href="#icon-bell"/></svg>
                <span class="notification-badge" id="notification-badge" hidden></span>
            </button>
            <div class="notification-panel" id="notification-panel" hidden>
                <div class="notification-panel-header">
                    <strong>Benachrichtigungen</strong>
                    <button type="button" class="btn btn-ghost btn-sm" id="notification-mark-all">Alle gelesen</button>
                </div>
                <ul class="notification-list" id="notification-list">
                    <li class="notification-empty">Keine Benachrichtigungen</li>
                </ul>
            </div>
        </div>
        <div class="topbar-user">
            <span><?= e($user['username'] ?? '') ?> · <?= e($user['role'] ?? '') ?></span>
            <span class="avatar" aria-hidden="true"><?= e(mb_substr((string) ($user['username'] ?? '?'), 0, 1)) ?></span>
        </div>
    </header>
    <main class="main-content" id="main">
        <?= $innerContent ?? '' ?>
    </main>
</div>
<?php
$content = ob_get_clean();
$scripts = array_merge($scripts ?? [], ['/js/notifications.js']);
include __DIR__ . '/layout.php';
