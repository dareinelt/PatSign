<?php
/**
 * Clearing-Übersicht: alle offenen Fälle mit Mehrfachauswahl und Sammelaktionen.
 * Erwartet: $cases, $csrf, $user, $clinicName
 */
require_once __DIR__ . '/../partials/helpers.php';

$statusLabels = [
    'open' => ['label' => 'Offen', 'badge' => 'badge-warning'],
    'in_progress' => ['label' => 'In Bearbeitung', 'badge' => 'badge-info'],
    'assigned' => ['label' => 'Zugeordnet', 'badge' => 'badge-success'],
    'completed' => ['label' => 'Abgeschlossen', 'badge' => 'badge-neutral'],
];
$cases = $cases ?? [];

$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '–';
    }
    $ts = strtotime($value);

    return $ts !== false ? date('d.m.Y H:i', $ts) : $value;
};
$formatBirth = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '–';
    }
    $ts = strtotime($value);

    return $ts !== false ? date('d.m.Y', $ts) : $value;
};

ob_start();
?>
<div class="dashboard-header">
    <div>
        <h1 class="page-title">Clearing</h1>
        <p class="page-subtitle mb-0">Dokumente, die nicht automatisch zugeordnet werden konnten – manuell prüfen, korrigieren und übernehmen</p>
    </div>
    <span class="badge <?= count($cases) > 0 ? 'badge-warning' : 'badge-success' ?>"><?= count($cases) ?> offene Vorgänge</span>
</div>

<div class="stack">
    <section class="card" aria-label="Offene Clearing-Fälle">
        <div class="card-header">
            <h2>Offene Vorgänge</h2>
            <div class="clearing-bulk-actions" id="clearing-bulk-actions" data-csrf="<?= e($csrf) ?>" hidden>
                <span id="clearing-selected-count" class="text-muted"></span>
                <button type="button" class="btn btn-primary btn-sm" id="bulk-assign-btn">
                    <svg class="icon" aria-hidden="true"><use href="#icon-users"/></svg>
                    Patient zuordnen
                </button>
                <button type="button" class="btn btn-secondary btn-sm" id="bulk-folder-btn">
                    <svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>
                    Neue Mappe
                </button>
                <button type="button" class="btn btn-ghost btn-sm" id="bulk-archive-btn">
                    <svg class="icon" aria-hidden="true"><use href="#icon-trash"/></svg>
                    Archivieren
                </button>
            </div>
        </div>
        <?php if ($cases === []): ?>
            <p class="table-empty mb-0">Aktuell keine offenen Clearing-Fälle. Alle Dokumente wurden zugeordnet.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table clearing-table" id="clearing-table">
                    <thead>
                        <tr>
                            <th scope="col">
                                <input type="checkbox" id="clearing-select-all" aria-label="Alle auswählen">
                            </th>
                            <th scope="col">Eingang</th>
                            <th scope="col">Dokument</th>
                            <th scope="col">Dokumenttyp</th>
                            <th scope="col">Fallnummer</th>
                            <th scope="col">Name</th>
                            <th scope="col">Geburtsdatum</th>
                            <th scope="col">KI-Konfidenz</th>
                            <th scope="col">Fehlergrund</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $case): ?>
                            <?php
                                $status = $statusLabels[$case['status']] ?? ['label' => $case['status'], 'badge' => 'badge-neutral'];
                                $confidence = $case['ai_confidence'];
                                $confidenceClass = $confidence === null ? '' : ($confidence < 0.5 ? 'is-low' : ($confidence < 0.8 ? 'is-medium' : 'is-high'));
                                $name = trim(($case['last_name'] ?? '') . ', ' . ($case['first_name'] ?? ''), ', ');
                            ?>
                            <tr data-case-id="<?= (int) $case['id'] ?>">
                                <td>
                                    <input type="checkbox" class="clearing-select" value="<?= (int) $case['id'] ?>"
                                           aria-label="Fall <?= (int) $case['id'] ?> auswählen">
                                </td>
                                <td><?= e($formatDate($case['created_at'])) ?></td>
                                <td class="clearing-filename" title="<?= e($case['file_name']) ?>"><?= e($case['file_name']) ?></td>
                                <td><?= e($case['document_type'] ?: 'Unbekannt') ?></td>
                                <td><?= ($case['case_number'] ?? '') !== '' && $case['case_number'] !== null ? e($case['case_number']) : '<span class="clearing-missing">fehlt</span>' ?></td>
                                <td><?= $name !== '' ? e($name) : '<span class="clearing-missing">fehlt</span>' ?></td>
                                <td><?= !empty($case['birth_date']) ? e($formatBirth($case['birth_date'])) : '<span class="clearing-missing">fehlt</span>' ?></td>
                                <td>
                                    <?php if ($confidence !== null): ?>
                                        <span class="confidence-pill <?= $confidenceClass ?>"><?= number_format($confidence * 100, 0) ?> %</span>
                                    <?php else: ?>
                                        –
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-danger"><?= e($case['error_label']) ?></span></td>
                                <td><span class="badge <?= e($status['badge']) ?>"><?= e($status['label']) ?></span></td>
                                <td>
                                    <a class="btn btn-primary btn-sm" href="/clearing/case?id=<?= (int) $case['id'] ?>">
                                        <svg class="icon" aria-hidden="true"><use href="#icon-pen"/></svg>
                                        Bearbeiten
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Dialog: Mehrfachauswahl einem bestehenden Patienten zuordnen -->
<dialog class="dialog" id="bulk-assign-dialog" aria-labelledby="bulk-assign-title">
    <div class="dialog-header">
        <h2 id="bulk-assign-title">Bestehendem Patienten zuordnen</h2>
        <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
    </div>
    <div class="dialog-body">
        <div class="form-group search-box clearing-patient-search">
            <label for="bulk-patient-search">Patient suchen (Fallnummer, Name, Geburtsdatum)</label>
            <input id="bulk-patient-search" type="text" autocomplete="off" placeholder="Live-Suche …">
            <ul class="search-results" id="bulk-patient-results" hidden></ul>
        </div>
        <p class="form-hint" id="bulk-assign-selected">Kein Patient ausgewählt.</p>
    </div>
    <div class="dialog-footer">
        <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
        <button type="button" class="btn btn-primary" id="bulk-assign-confirm" disabled>Zuordnen und übernehmen</button>
    </div>
</dialog>

<!-- Dialog: Neue Patientenmappe für Mehrfachauswahl -->
<dialog class="dialog" id="bulk-folder-dialog" aria-labelledby="bulk-folder-title">
    <form id="bulk-folder-form">
        <div class="dialog-header">
            <h2 id="bulk-folder-title">Neue Patientenmappe erstellen</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <div class="form-group">
                <label for="bulk-folder-case-number">Fallnummer (optional)</label>
                <input id="bulk-folder-case-number" name="case_number" inputmode="numeric" placeholder="z. B. 92612345">
                <span class="form-hint">Ohne Fallnummer wird eine temporäre Patientenmappe erstellt.</span>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="bulk-folder-first-name">Vorname</label>
                    <input id="bulk-folder-first-name" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="bulk-folder-last-name">Nachname</label>
                    <input id="bulk-folder-last-name" name="last_name" required>
                </div>
            </div>
            <div class="form-group">
                <label for="bulk-folder-birth-date">Geburtsdatum</label>
                <input id="bulk-folder-birth-date" name="birth_date" placeholder="TT.MM.JJJJ" required>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Mappe erstellen und übernehmen</button>
        </div>
    </form>
</dialog>
<?php
$innerContent = ob_get_clean();
$title = 'Clearing – PatSign';
$activeNav = 'clearing';
$areaLabel = 'Personalbereich';
$scripts = ['/js/clearing.js'];
include __DIR__ . '/../partials/staff_layout.php';
