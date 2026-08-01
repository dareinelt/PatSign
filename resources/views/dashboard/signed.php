<?php
require_once __DIR__ . '/../partials/helpers.php';

$signedDocuments = $signedDocuments ?? [];

ob_start();
?>
<div class="dashboard-header">
    <div>
        <h1 class="page-title">Heute unterschriebene Dokumente</h1>
        <p class="page-subtitle mb-0">Übersicht aller Dokumente, die heute signiert wurden – mit Vorschau</p>
    </div>
    <a class="btn btn-secondary" href="/dashboard">
        <svg class="icon" aria-hidden="true"><use href="#icon-home"/></svg>
        Zurück zum Dashboard
    </a>
</div>

<div class="stack">
    <?php if (empty($signedDocuments)): ?>
        <section class="card" aria-label="Keine Dokumente">
            <p class="table-empty mb-0">Heute wurden noch keine Dokumente unterschrieben.</p>
        </section>
    <?php else: ?>
        <p class="page-subtitle"><?= count($signedDocuments) ?> Dokument(e) heute unterschrieben</p>
        <div class="signed-doc-grid">
            <?php foreach ($signedDocuments as $document): ?>
                <section class="card signed-doc-card" aria-label="Unterschriebenes Dokument">
                    <div class="card-header">
                        <h2><?= e($document['document_type'] ?? 'Unbekannt') ?></h2>
                        <span class="badge badge-success"><?= ($document['status'] ?? '') === 'sent' ? 'Versendet' : 'Unterschrieben' ?></span>
                    </div>
                    <div class="signed-doc-meta">
                        <div class="patient-name"><?= e(trim(($document['last_name'] ?? '') . ', ' . ($document['first_name'] ?? ''), ', ') ?: 'Unbekannt') ?></div>
                        <div class="patient-meta">
                            Fallnummer <?= e($document['case_number'] ?? '–') ?> ·
                            Unterschrieben um <?= e(substr((string) ($document['signed_at'] ?? ''), 11, 5) ?: '–') ?> Uhr
                            <?php if (!empty($document['operator_name'])): ?>
                                · Personal: <?= e($document['operator_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="signed-doc-preview" data-pdf-preview data-document-id="<?= (int) $document['id'] ?>">
                        <p class="pdf-viewer-message">Vorschau wird geladen …</p>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$innerContent = ob_get_clean();
$title = 'Unterschriebene Dokumente – PatSign';
$activeNav = 'dashboard';
$areaLabel = 'Personalbereich';
$scripts = ['/js/pdf-viewer.js', '/js/signed-overview.js'];
include __DIR__ . '/../partials/staff_layout.php';
