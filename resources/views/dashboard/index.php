<?php
require_once __DIR__ . '/../partials/helpers.php';

$statusLabels = [
    'imported' => 'Importiert',
    'analyzing' => 'KI wird ausgeführt',
    'analyzed' => 'Zuordnung erfolgreich',
    'ready' => 'Bereit zur Unterschrift',
    'signed' => 'Unterschrieben',
    'sent' => 'Versendet',
    'error' => 'Fehler',
];
$statusCounts = $statusCounts ?? [];
$eventLabels = [
    'patient_session_started' => 'Patientenmodus gestartet',
    'documents_signed' => 'Signatur abgeschlossen',
    'mail_sent' => 'E-Mail versendet',
    'mail_error' => 'Fehler beim Mailversand',
    'document_imported' => 'Dokument importiert',
    'document_analyzed' => 'Analyse abgeschlossen',
    'device_folder_sent' => 'Patientenmappe an Gerät gesendet',
    'device_session_started' => 'Gerätesitzung gestartet',
    'device_session_ended' => 'Gerätesitzung beendet',
    'device_signature_completed' => 'Unterschrift am Gerät abgeschlossen',
];

$deviceAvailabilityMeta = [
    'free' => ['label' => 'Frei', 'badge' => 'badge-success'],
    'busy' => ['label' => 'Belegt', 'badge' => 'badge-warning'],
    'offline' => ['label' => 'Offline', 'badge' => 'badge-neutral'],
    'locked' => ['label' => 'Gesperrt', 'badge' => 'badge-danger'],
    'retired' => ['label' => 'Außer Betrieb', 'badge' => 'badge-neutral'],
];
$devices = $devices ?? [];

ob_start();
?>
<div class="dashboard-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle mb-0">Aktueller Überblick über Patienten, Dokumente und System</p>
    </div>
    <div class="search-box">
        <svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg>
        <label for="dashboard-search" class="visually-hidden">Nach Name, Vorname oder Fallnummer suchen</label>
        <input id="dashboard-search" type="text" placeholder="Name, Vorname oder Fallnummer suchen …" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="dashboard-search-results">
        <ul class="search-results" id="dashboard-search-results" hidden></ul>
    </div>
</div>

<div class="stack">
    <section aria-label="Schnellaktionen">
        <div class="quick-actions">
            <a class="quick-action" href="/documents">
                <svg class="icon" aria-hidden="true"><use href="#icon-import"/></svg>
                Dokument importieren
            </a>
            <button class="quick-action" type="button" data-dialog-open="patient-start-dialog">
                <svg class="icon" aria-hidden="true"><use href="#icon-folder"/></svg>
                Patientenmappe öffnen
            </button>
            <button class="quick-action" type="button" id="dashboard-focus-search">
                <svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg>
                Dokument suchen
            </button>
            <button class="quick-action" type="button" id="dashboard-refresh">
                <svg class="icon" aria-hidden="true"><use href="#icon-refresh"/></svg>
                Dashboard aktualisieren
            </button>
        </div>
    </section>

    <section aria-label="Dokumentenstatus">
        <div class="grid grid-4">
            <?php foreach ($statusLabels as $key => $label): ?>
                <?php if ($key === 'signed'): ?>
                    <a class="stat-card is-success stat-card-link" href="/dashboard/signed" aria-label="Unterschriebene Dokumente von heute anzeigen">
                        <span class="stat-value"><?= (int) ($statusCounts[$key] ?? 0) ?></span>
                        <span class="stat-label"><?= e($label) ?></span>
                    </a>
                <?php else: ?>
                    <div class="stat-card <?= $key === 'error' ? 'is-error' : '' ?> <?= $key === 'sent' ? 'is-success' : '' ?>">
                        <span class="stat-value"><?= (int) ($statusCounts[$key] ?? 0) ?></span>
                        <span class="stat-label"><?= e($label) ?></span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="dashboard-grid">
        <section class="card col-span-8" aria-label="Wartende Patienten">
            <div class="card-header">
                <h2>Wartende Patienten</h2>
                <span class="badge badge-info"><?= count($waitingPatients ?? []) ?> offen</span>
            </div>
            <?php if (empty($waitingPatients)): ?>
                <p class="table-empty mb-0">Aktuell warten keine Patienten.</p>
            <?php else: ?>
                <?php foreach ($waitingPatients as $patient): ?>
                    <div class="patient-row">
                        <div>
                            <div class="patient-name"><?= e(trim(($patient['last_name'] ?? '') . ', ' . ($patient['first_name'] ?? ''), ', ') ?: 'Unbekannt') ?></div>
                            <div class="patient-meta">
                                Fallnummer <?= e($patient['case_number']) ?> ·
                                <?= (int) $patient['document_count'] ?> Dokument(e)
                            </div>
                        </div>
                        <?php if ((int) ($patient['error_count'] ?? 0) > 0): ?>
                            <span class="badge badge-danger">Fehler</span>
                        <?php elseif ((int) ($patient['ready_count'] ?? 0) > 0): ?>
                            <span class="badge badge-success">Bereit zur Unterschrift</span>
                        <?php else: ?>
                            <span class="badge badge-warning">In Bearbeitung</span>
                        <?php endif; ?>
                        <div class="patient-actions">
                            <form method="post" action="/patient/start">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="case_number" value="<?= e($patient['case_number']) ?>">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <svg class="icon" aria-hidden="true"><use href="#icon-pen"/></svg>
                                    Patientenmodus
                                </button>
                            </form>
                            <button type="button" class="btn btn-secondary btn-sm" data-send-to-device
                                    data-case-number="<?= e($patient['case_number']) ?>"
                                    data-patient-name="<?= e(trim(($patient['last_name'] ?? '') . ', ' . ($patient['first_name'] ?? ''), ', ')) ?>">
                                <svg class="icon" aria-hidden="true"><use href="#icon-document"/></svg>
                                An Gerät senden
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="card col-span-4" aria-label="Systemstatus">
            <div class="card-header">
                <h2>Systemstatus</h2>
            </div>
            <ul class="status-list">
                <?php foreach ($systemChecks ?? [] as $check): ?>
                    <li>
                        <span class="status-dot is-<?= e($check['status']) ?>" aria-hidden="true"></span>
                        <span><?= e($check['label']) ?></span>
                        <span class="visually-hidden">
                            <?= $check['status'] === 'ok' ? 'in Ordnung' : ($check['status'] === 'warn' ? 'Warnung' : 'Fehler') ?>
                        </span>
                        <span class="status-detail"><?= e($check['detail']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card col-span-12" aria-label="Signaturgeräte">
            <div class="card-header">
                <h2>Signaturgeräte (iPads)</h2>
                <span class="badge badge-info" id="device-count"><?= count($devices) ?> Gerät(e)</span>
            </div>
            <?php if (empty($devices)): ?>
                <p class="table-empty mb-0">Noch keine Geräte registriert. Rufen Sie die Anwendung auf einem iPad auf, um es zu registrieren.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table" id="device-table">
                        <thead>
                            <tr>
                                <th scope="col">Gerätename</th>
                                <th scope="col">Status</th>
                                <th scope="col">Zugewiesener Patient</th>
                                <th scope="col">Letzte Aktivität</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $device): ?>
                                <?php $meta = $deviceAvailabilityMeta[$device['availability']] ?? ['label' => $device['availability'], 'badge' => 'badge-neutral']; ?>
                                <tr>
                                    <td><?= e($device['name']) ?></td>
                                    <td><span class="badge <?= e($meta['badge']) ?>"><?= e($meta['label']) ?></span></td>
                                    <td><?= e($device['assigned_patient'] ?? ($device['assigned_case_number'] ? 'Fall ' . $device['assigned_case_number'] : '–')) ?></td>
                                    <td><?= e($device['last_seen_at'] ?? '–') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card col-span-12" aria-label="Letzte Aktivitäten">
            <div class="card-header">
                <h2>Letzte Aktivitäten</h2>
            </div>
            <?php if (empty($activities)): ?>
                <p class="table-empty mb-0">Noch keine Aktivitäten aufgezeichnet.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($activities as $activity): ?>
                        <?php $isError = str_contains((string) $activity['event_type'], 'error'); ?>
                        <li class="<?= $isError ? 'is-error' : '' ?>">
                            <span class="timeline-dot" aria-hidden="true"></span>
                            <div><?= e($eventLabels[$activity['event_type']] ?? $activity['event_type']) ?></div>
                            <div class="timeline-time"><?= e($activity['created_at']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<dialog class="dialog" id="patient-start-dialog" aria-labelledby="patient-start-title">
    <form method="post" action="/patient/start">
        <div class="dialog-header">
            <h2 id="patient-start-title">Patientenmappe öffnen</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="start-case-number">Fallnummer</label>
                <input id="start-case-number" name="case_number" inputmode="numeric" placeholder="z. B. 92612345" required>
                <span class="form-hint">Startet den Patientenmodus für alle Dokumente dieser Fallnummer.</span>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Patientenmodus starten</button>
        </div>
    </form>
</dialog>
<dialog class="dialog" id="send-to-device-dialog" aria-labelledby="send-to-device-title">
    <form id="send-to-device-form">
        <div class="dialog-header">
            <h2 id="send-to-device-title">Patientenmappe an Gerät senden</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="case_number" id="send-device-case-number" value="">
            <p class="mb-2" id="send-device-patient-info"></p>
            <div class="form-group">
                <label for="send-device-select">Zielgerät</label>
                <select id="send-device-select" name="device_id" required>
                    <option value="">Geräte werden geladen …</option>
                </select>
                <span class="form-hint">Nur freie Geräte sind auswählbar.</span>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary" id="send-device-submit">Senden</button>
        </div>
    </form>
</dialog>

<?php
$innerContent = ob_get_clean();
$title = 'Dashboard – PatSign';
$activeNav = 'dashboard';
$areaLabel = 'Personalbereich';
$scripts = ['/js/dashboard.js'];
include __DIR__ . '/../partials/staff_layout.php';
