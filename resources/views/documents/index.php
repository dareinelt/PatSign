<?php ob_start(); ?>
<div class="card">
    <h1>Dokumentenimport</h1>
    <form method="post" action="/documents/upload" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrf ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label>PDF auswählen</label>
        <input type="file" name="document" accept="application/pdf" required>
        <button type="submit">Importieren</button>
    </form>
</div>
<div class="card">
    <h2>Signatur-Zustimmung</h2>
    <label><input type="checkbox" disabled> Ich stimme der Übermittlung meiner Unterlagen per E-Mail zu.</label>
</div>
<?php $content = ob_get_clean(); $title = 'Dokumente'; include __DIR__ . '/../partials/layout.php'; ?>
