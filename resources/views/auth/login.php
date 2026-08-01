<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <span class="brand-logo" aria-hidden="true">PS</span>
            <div>
                <h1 class="mb-0 text-lg">PatSign</h1>
                <p class="text-muted text-sm mb-0">Digitale Patientenunterschrift</p>
            </div>
        </div>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="/login">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="redirect_to_kiosk" id="login-redirect-to-kiosk" value="0">
            <div class="form-group">
                <label for="login-username">Benutzername</label>
                <input id="login-username" name="username" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="login-password">Passwort</label>
                <input id="login-password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">Anmelden</button>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); $title = 'Anmeldung – PatSign'; $scripts = ['/js/device-detect.js']; include __DIR__ . '/../partials/layout.php'; ?>
