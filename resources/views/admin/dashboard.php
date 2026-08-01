<?php ob_start(); ?>
<div class="card">
    <h1>Administration</h1>
    <p>Vision-Modell: <?= htmlspecialchars((string) $visionModel, ENT_QUOTES, 'UTF-8') ?></p>
    <p>Analysemodell: <?= htmlspecialchars((string) $analysisModel, ENT_QUOTES, 'UTF-8') ?></p>
</div>
<div class="card">
    <h2>Promptverwaltung</h2>
    <form method="post" action="/admin/prompts">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label>Typ</label>
        <select name="type"><option value="vision">vision</option><option value="analysis">analysis</option></select>
        <label>Prompt</label>
        <textarea name="content" rows="8" required></textarea>
        <label><input type="checkbox" name="activate" value="1" checked> Sofort aktivieren</label>
        <button type="submit">Speichern</button>
    </form>
</div>
<?php $content = ob_get_clean(); $title = 'Administration'; include __DIR__ . '/../partials/layout.php'; ?>
