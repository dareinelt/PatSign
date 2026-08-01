<?php
require_once __DIR__ . '/../partials/helpers.php';

ob_start();
?>
<h1 class="page-title">Dokumente</h1>
<p class="page-subtitle">PDF-Dokumente importieren und für den Signaturprozess vorbereiten</p>

<div class="grid grid-2">
    <section class="card" aria-label="Dokument importieren">
        <div class="card-header">
            <h2>Dokument importieren</h2>
        </div>
        <form method="post" action="/documents/upload" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <div class="form-group">
                <label for="document-upload">PDF auswählen</label>
                <input type="file" id="document-upload" name="document" accept="application/pdf" required>
                <span class="form-hint">Nur PDF-Dateien, maximal 15 MB.</span>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <svg class="icon" aria-hidden="true"><use href="#icon-import"/></svg>
                    Importieren
                </button>
            </div>
        </form>
    </section>

    <section class="card" aria-label="Importordner">
        <div class="card-header">
            <h2>Importordner</h2>
        </div>
        <p class="text-muted">Prüft den konfigurierten Importpfad auf neue PDF-Dokumente.</p>
        <a class="btn btn-secondary" href="/documents/watch">
            <svg class="icon" aria-hidden="true"><use href="#icon-refresh"/></svg>
            Importordner scannen
        </a>
    </section>
</div>
<?php
$innerContent = ob_get_clean();
$title = 'Dokumente – PatSign';
$activeNav = 'documents';
$areaLabel = 'Personalbereich';
$user = $_SESSION['auth_user'] ?? [];
include __DIR__ . '/../partials/staff_layout.php';
