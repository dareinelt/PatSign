<?php ob_start(); ?>
<div class="card">
    <h1>Anmeldung</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label>Benutzername</label>
        <input name="username" required>
        <label>Passwort</label>
        <input name="password" type="password" required>
        <button type="submit">Login</button>
    </form>
</div>
<?php $content = ob_get_clean(); $title = 'Login'; include __DIR__ . '/../partials/layout.php'; ?>
