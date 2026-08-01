<?php
/**
 * Clearing-Detailansicht: große Dokumentenvorschau parallel zu KI-Ergebnis,
 * Bearbeitung, Patientenzuordnung, Neuanalyse und Historie.
 * Erwartet: $case, $csrf, $user, $clinicName, $isAdmin, $allowTemporaryFolders, $maxAiAttempts
 */
require_once __DIR__ . '/../partials/helpers.php';

$statusLabels = [
    'open' => ['label' => 'Offen', 'badge' => 'badge-warning'],
    'in_progress' => ['label' => 'In Bearbeitung', 'badge' => 'badge-info'],
    'assigned' => ['label' => 'Zugeordnet', 'badge' => 'badge-success'],
    'completed' => ['label' => 'Abgeschlossen', 'badge' => 'badge-neutral'],
];
$historyLabels = [
    'clearing_created' => 'Ins Clearing verschoben',
    'values_updated' => 'Werte manuell geändert',
    'assigned' => 'Dokument zugeordnet und übernommen',
    'archived' => 'Dokument archiviert',
    'completed' => 'Clearing abgeschlossen',
    'reanalysis_started' => 'KI-Neuanalyse gestartet',
    'reanalysis_succeeded' => 'KI-Neuanalyse erfolgreich',
    'reanalysis_failed' => 'KI-Neuanalyse fehlgeschlagen',
];

$status = $statusLabels[$case['status']] ?? ['label' => $case['status'], 'badge' => 'badge-neutral'];
$detected = $case['detected'] ?? [];
$effective = $case['effective'] ?? [];
$isOpen = in_array((string) $case['status'], ['open', 'in_progress'], true);
$confidence = $case['ai_confidence'] !== null ? (float) $case['ai_confidence'] : null;
$confidenceClass = $confidence === null ? '' : ($confidence < 0.5 ? 'is-low' : ($confidence < 0.8 ? 'is-medium' : 'is-high'));
$attemptsUsed = count($case['analysis_runs'] ?? []);

$fmtBirth = static function (?string $value): string {
    if ($value === null || trim((string) $value) === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return date('d.m.Y', (int) strtotime($value));
    }

    return (string) $value;
};

ob_start();
?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumbs">
        <li><a href="/dashboard">Dashboard</a></li>
        <li><a href="/clearing">Clearing</a></li>
        <li><span aria-current="page"><?= e(basename((string) $case['original_path'])) ?></span></li>
    </ol>
</nav>

<div class="dashboard-header">
    <div>
        <h1 class="page-title">Clearing-Fall bearbeiten</h1>
        <p class="page-subtitle mb-0">
            <?= e(basename((string) $case['original_path'])) ?> ·
            Eingang <?= e(date('d.m.Y H:i', (int) strtotime((string) $case['document_created_at']))) ?>
        </p>
    </div>
    <div class="clearing-detail-status">
        <span class="badge badge-danger"><?= e($case['error_label']) ?></span>
        <span class="badge <?= e($status['badge']) ?>" id="clearing-status-badge"><?= e($status['label']) ?></span>
    </div>
</div>

<div class="clearing-detail" id="clearing-detail"
     data-case-id="<?= (int) $case['id'] ?>"
     data-csrf="<?= e($csrf) ?>">

    <!-- Dokumentenvorschau -->
    <section class="card clearing-preview-card" aria-label="Dokumentenvorschau">
        <div class="card-header">
            <h2>Dokumentenvorschau</h2>
            <div class="clearing-zoom-controls" role="group" aria-label="Zoom">
                <button type="button" class="btn btn-secondary btn-sm" id="pdf-zoom-out" aria-label="Verkleinern">−</button>
                <span id="pdf-zoom-level" aria-live="polite">100 %</span>
                <button type="button" class="btn btn-secondary btn-sm" id="pdf-zoom-in" aria-label="Vergrößern">+</button>
            </div>
        </div>
        <div class="pdf-viewer clearing-pdf-viewer" id="clearing-pdf-viewer"
             data-src="/clearing/document?id=<?= (int) $case['id'] ?>"></div>
    </section>

    <!-- Metadaten und Aktionen -->
    <div class="stack clearing-side">
        <section class="card" aria-label="KI-Ergebnis">
            <div class="card-header">
                <h2>KI-Ergebnis</h2>
                <?php if ($confidence !== null): ?>
                    <span class="confidence-pill <?= $confidenceClass ?>">Konfidenz <?= number_format($confidence * 100, 0) ?> %</span>
                <?php endif; ?>
            </div>
            <dl class="clearing-values">
                <div><dt>Dokumenttyp</dt><dd><?= e($detected['document_type'] ?? '–') ?></dd></div>
                <div><dt>Fallnummer</dt><dd><?= e($detected['case_number'] ?? '–') ?></dd></div>
                <div><dt>Vorname</dt><dd><?= e($detected['first_name'] ?? '–') ?></dd></div>
                <div><dt>Nachname</dt><dd><?= e($detected['last_name'] ?? '–') ?></dd></div>
                <div><dt>Geburtsdatum</dt><dd><?= e($fmtBirth($detected['birth_date'] ?? null) ?: '–') ?></dd></div>
            </dl>
            <?php if (!empty($isAdmin)): ?>
                <details class="clearing-json">
                    <summary>Vollständige JSON-Antwort der KI</summary>
                    <pre><?= e(json_encode($detected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
            <?php endif; ?>
        </section>

        <?php if ($isOpen): ?>
            <section class="card" aria-label="Bearbeitung">
                <div class="card-header">
                    <h2>Werte korrigieren</h2>
                </div>
                <form id="clearing-update-form">
                    <div class="form-group">
                        <label for="edit-document-type">Dokumenttyp</label>
                        <input id="edit-document-type" name="document_type" value="<?= e($effective['document_type'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="edit-case-number">Fallnummer</label>
                        <input id="edit-case-number" name="case_number" inputmode="numeric" placeholder="z. B. 92612345"
                               value="<?= e($effective['case_number'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-first-name">Vorname</label>
                            <input id="edit-first-name" name="first_name" value="<?= e($effective['first_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="edit-last-name">Nachname</label>
                            <input id="edit-last-name" name="last_name" value="<?= e($effective['last_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit-birth-date">Geburtsdatum</label>
                        <input id="edit-birth-date" name="birth_date" placeholder="TT.MM.JJJJ"
                               value="<?= e($fmtBirth($effective['birth_date'] ?? null)) ?>">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <svg class="icon" aria-hidden="true"><use href="#icon-check"/></svg>
                            Werte speichern
                        </button>
                    </div>
                </form>
            </section>

            <section class="card" aria-label="Patientenzuordnung">
                <div class="card-header">
                    <h2>Patient zuordnen</h2>
                </div>
                <div class="form-group search-box clearing-patient-search">
                    <label for="patient-search">Bestehenden Patienten suchen</label>
                    <input id="patient-search" type="text" autocomplete="off"
                           placeholder="Fallnummer, Nachname, Vorname oder Geburtsdatum …">
                    <ul class="search-results" id="patient-search-results" hidden></ul>
                </div>
                <p class="form-hint">Auswahl übernimmt das Dokument direkt in die Patientenmappe.</p>

                <details class="clearing-new-folder">
                    <summary>Neue Patientenmappe erstellen</summary>
                    <form id="clearing-folder-form" class="mt-2">
                        <div class="form-group">
                            <label for="folder-case-number">Fallnummer (optional)</label>
                            <input id="folder-case-number" name="case_number" inputmode="numeric" placeholder="z. B. 92612345">
                            <?php if (!empty($allowTemporaryFolders)): ?>
                                <span class="form-hint">Ohne Fallnummer wird eine <strong>temporäre</strong> Patientenmappe erstellt – die Fallnummer kann später nachgetragen werden.</span>
                            <?php else: ?>
                                <span class="form-hint">Temporäre Mappen sind deaktiviert – Fallnummer erforderlich.</span>
                            <?php endif; ?>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="folder-first-name">Vorname</label>
                                <input id="folder-first-name" name="first_name" required>
                            </div>
                            <div class="form-group">
                                <label for="folder-last-name">Nachname</label>
                                <input id="folder-last-name" name="last_name" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="folder-birth-date">Geburtsdatum</label>
                            <input id="folder-birth-date" name="birth_date" placeholder="TT.MM.JJJJ" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>
                                Mappe erstellen und übernehmen
                            </button>
                        </div>
                    </form>
                </details>
            </section>

            <section class="card" aria-label="KI erneut ausführen">
                <div class="card-header">
                    <h2>Analyse erneut starten</h2>
                    <span class="badge badge-info"><?= (int) $attemptsUsed ?> / <?= (int) $maxAiAttempts ?> Versuche</span>
                </div>
                <p class="text-muted">Nutzt die aktuell konfigurierten Prompts und Modelle. Vorherige Ergebnisse bleiben in der Historie erhalten.</p>
                <div class="clearing-reanalyze-actions">
                    <button type="button" class="btn btn-secondary" data-reanalyze="vision">Vision erneut</button>
                    <button type="button" class="btn btn-secondary" data-reanalyze="analysis">Analyse erneut</button>
                    <button type="button" class="btn btn-primary" data-reanalyze="both">Beide Modelle erneut</button>
                </div>
            </section>

            <section class="card" aria-label="Weitere Aktionen">
                <div class="card-header">
                    <h2>Weitere Aktionen</h2>
                </div>
                <div class="clearing-reanalyze-actions">
                    <button type="button" class="btn btn-ghost" id="clearing-archive-btn">
                        <svg class="icon" aria-hidden="true"><use href="#icon-trash"/></svg>
                        Dokument archivieren
                    </button>
                </div>
            </section>
        <?php else: ?>
            <section class="card" aria-label="Abschluss">
                <div class="card-header">
                    <h2>Fall <?= e($status['label']) ?></h2>
                </div>
                <p class="text-muted mb-0">
                    Dieser Clearing-Fall wurde bereits bearbeitet.
                    <?php if ((string) $case['status'] === 'assigned'): ?>
                        Das Dokument ist Bestandteil der Patientenmappe
                        <?= !empty($case['case_number']) ? '(Fall ' . e($case['case_number']) . ')' : '' ?>
                        und durchläuft den regulären Workflow.
                    <?php endif; ?>
                </p>
                <?php if ((string) $case['status'] === 'assigned'): ?>
                    <div class="form-actions">
                        <button type="button" class="btn btn-primary" id="clearing-complete-btn">Clearing abschließen</button>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card" aria-label="Historie">
            <div class="card-header">
                <h2>Historie</h2>
            </div>
            <?php if (empty($case['history'])): ?>
                <p class="table-empty mb-0">Keine Einträge vorhanden.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($case['history'] as $entry): ?>
                        <?php $isError = str_contains((string) $entry['event_type'], 'failed'); ?>
                        <li class="<?= $isError ? 'is-error' : '' ?>">
                            <span class="timeline-dot" aria-hidden="true"></span>
                            <div>
                                <?= e($historyLabels[$entry['event_type']] ?? $entry['event_type']) ?>
                                <?php if (!empty($entry['username'])): ?>
                                    · <?= e($entry['username']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="timeline-time"><?= e($entry['created_at']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if (!empty($case['analysis_runs'])): ?>
            <section class="card" aria-label="KI-Läufe">
                <div class="card-header">
                    <h2>KI-Läufe</h2>
                </div>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Zeitpunkt</th>
                                <th scope="col">Modus</th>
                                <th scope="col">Ergebnis</th>
                                <th scope="col">Dauer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($case['analysis_runs'] as $run): ?>
                                <tr>
                                    <td><?= e($run['created_at']) ?></td>
                                    <td><?= e($run['run_mode']) ?></td>
                                    <td>
                                        <?php if ((int) $run['success'] === 1): ?>
                                            <span class="badge badge-success">Erfolgreich</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" title="<?= e($run['error_message'] ?? '') ?>">Fehlgeschlagen</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $run['duration_ms'] !== null ? e(number_format((int) $run['duration_ms'] / 1000, 1, ',', '.')) . ' s' : '–' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php
$innerContent = ob_get_clean();
$title = 'Clearing-Fall – PatSign';
$activeNav = 'clearing';
$areaLabel = 'Personalbereich';
$scripts = ['/js/pdf-viewer.js', '/js/clearing-detail.js'];
include __DIR__ . '/../partials/staff_layout.php';
