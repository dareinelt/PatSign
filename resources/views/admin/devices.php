<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Registrierte Signaturgeräte, Sitzungen und Protokolle verwalten';

$statusMeta = [
    'active' => ['label' => 'Aktiv', 'badge' => 'badge-success'],
    'locked' => ['label' => 'Gesperrt', 'badge' => 'badge-danger'],
    'retired' => ['label' => 'Außer Betrieb', 'badge' => 'badge-neutral'],
];
$availabilityMeta = [
    'free' => ['label' => 'Frei', 'badge' => 'badge-success'],
    'busy' => ['label' => 'Belegt', 'badge' => 'badge-warning'],
    'offline' => ['label' => 'Offline', 'badge' => 'badge-neutral'],
    'locked' => ['label' => 'Gesperrt', 'badge' => 'badge-danger'],
    'retired' => ['label' => 'Außer Betrieb', 'badge' => 'badge-neutral'],
];
$assignmentStatusLabels = [
    'pending' => 'Wartet auf Gerät',
    'active' => 'Aktiv',
    'completed' => 'Abgeschlossen',
    'cancelled' => 'Abgebrochen',
    'expired' => 'Abgelaufen',
];
$historyLabels = [
    'device_registered' => 'Gerät registriert',
    'device_renamed' => 'Gerät umbenannt',
    'device_locked' => 'Gerät gesperrt',
    'device_activated' => 'Gerät aktiviert',
    'device_retired' => 'Gerät außer Betrieb genommen',
    'device_reset' => 'Gerät zurückgesetzt',
    'device_deleted' => 'Gerät gelöscht',
    'folder_sent' => 'Patientenmappe gesendet',
    'session_started' => 'Sitzung gestartet',
    'session_ended' => 'Sitzung beendet',
    'device_timeout' => 'Timeout (Zuweisung abgelaufen)',
    'token_renewed' => 'Token erneuert',
    'signature_completed' => 'Unterschrift abgeschlossen',
];

ob_start();
?>
<div class="stack">
    <div class="card">
        <div class="card-header">
            <h2>Geräteübersicht</h2>
            <span class="badge badge-info"><?= count($devices ?? []) ?> Gerät(e)</span>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Gerätename</th>
                        <th scope="col">Status</th>
                        <th scope="col">Verfügbarkeit</th>
                        <th scope="col">Letzte Aktivität</th>
                        <th scope="col">Zugewiesener Patient</th>
                        <th scope="col">Letzter Benutzer</th>
                        <th scope="col">Version</th>
                        <th scope="col">Browser / OS</th>
                        <th scope="col" class="text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($devices)): ?>
                        <tr><td colspan="9" class="table-empty">Noch keine Geräte registriert.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($devices ?? [] as $device): ?>
                        <?php
                        $status = $statusMeta[$device['status']] ?? ['label' => $device['status'], 'badge' => 'badge-neutral'];
                        $availability = $availabilityMeta[$device['availability']] ?? ['label' => $device['availability'], 'badge' => 'badge-neutral'];
                        $hasOpenAssignment = !empty($device['assignment_id']);
                        ?>
                        <tr>
                            <td><?= e($device['name']) ?></td>
                            <td><span class="badge <?= e($status['badge']) ?>"><?= e($status['label']) ?></span></td>
                            <td><span class="badge <?= e($availability['badge']) ?>"><?= e($availability['label']) ?></span></td>
                            <td><?= e($device['last_seen_at'] ?? '–') ?></td>
                            <td><?= e($device['assigned_patient'] ?? ($device['assigned_case_number'] ? 'Fall ' . $device['assigned_case_number'] : '–')) ?></td>
                            <td><?= e($device['last_user'] ?? '–') ?></td>
                            <td><?= e($device['software_version'] ?? '–') ?></td>
                            <td><?= e(trim(($device['browser'] ?? '') . ' / ' . ($device['os'] ?? ''), ' /') ?: '–') ?></td>
                            <td>
                                <div class="table-actions">
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            data-edit-item="device-rename-dialog"
                                            data-item='<?= e(json_encode(['id' => $device['id'], 'name' => $device['name']], JSON_HEX_APOS)) ?>'>
                                        Umbenennen
                                    </button>
                                    <?php if ($hasOpenAssignment): ?>
                                        <form method="post" action="/admin/devices" data-confirm="Aktive Sitzung von „<?= e($device['name']) ?>“ wirklich beenden?">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="end_session">
                                            <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Sitzung beenden</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($device['status'] === 'active'): ?>
                                        <form method="post" action="/admin/devices">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="lock">
                                            <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Sperren</button>
                                        </form>
                                        <form method="post" action="/admin/devices" data-confirm="Gerät „<?= e($device['name']) ?>“ außer Betrieb nehmen?">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="retire">
                                            <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Deaktivieren</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/admin/devices">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="unlock">
                                            <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Entsperren</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="/admin/devices" data-confirm="Gerät „<?= e($device['name']) ?>“ zurücksetzen? Das Gerät muss sich danach neu registrieren.">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="reset">
                                        <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">Zurücksetzen</button>
                                    </form>
                                    <form method="post" action="/admin/devices" data-confirm="Gerät „<?= e($device['name']) ?>“ wirklich löschen?">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Aktive Sitzungen</h2>
            <span class="badge badge-info"><?= count($activeSessions ?? []) ?> aktiv</span>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Gerät</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Fallnummer</th>
                        <th scope="col">Gestartet</th>
                        <th scope="col">Läuft ab</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activeSessions)): ?>
                        <tr><td colspan="5" class="table-empty">Keine aktiven Sitzungen.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($activeSessions ?? [] as $session): ?>
                        <tr>
                            <td><?= e($session['device_name']) ?></td>
                            <td><?= e($session['patient_name'] ?? '–') ?></td>
                            <td><?= e($session['case_number'] ?? '–') ?></td>
                            <td><?= e($session['started_at']) ?></td>
                            <td><?= e($session['expires_at'] ?? '–') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Zuweisungsprotokoll</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Zeitpunkt</th>
                        <th scope="col">Gerät</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Fallnummer</th>
                        <th scope="col">Zugewiesen von</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignmentLog)): ?>
                        <tr><td colspan="6" class="table-empty">Noch keine Zuweisungen.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($assignmentLog ?? [] as $entry): ?>
                        <tr>
                            <td><?= e($entry['assigned_at']) ?></td>
                            <td><?= e($entry['device_name'] ?? '–') ?></td>
                            <td><?= e($entry['patient_name'] ?? '–') ?></td>
                            <td><?= e($entry['case_number']) ?></td>
                            <td><?= e($entry['assigned_by_name'] ?? '–') ?></td>
                            <td><?= e($assignmentStatusLabels[$entry['status']] ?? $entry['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Gerätehistorie</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Zeitpunkt</th>
                        <th scope="col">Gerät</th>
                        <th scope="col">Ereignis</th>
                        <th scope="col">Ausgelöst von</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deviceHistory)): ?>
                        <tr><td colspan="4" class="table-empty">Noch keine Einträge.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($deviceHistory ?? [] as $entry): ?>
                        <tr>
                            <td><?= e($entry['created_at']) ?></td>
                            <td><?= e($entry['device_name'] ?? '–') ?></td>
                            <td><?= e($historyLabels[$entry['event_type']] ?? $entry['event_type']) ?></td>
                            <td><?= e($entry['created_by_name'] ?? 'System/Gerät') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<dialog class="dialog" id="device-rename-dialog" aria-labelledby="device-rename-title">
    <form method="post" action="/admin/devices">
        <div class="dialog-header">
            <h2 id="device-rename-title">Gerät umbenennen</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label for="device-rename-name">Gerätename</label>
                <input id="device-rename-name" name="name" maxlength="120" required autocomplete="off">
                <span class="form-hint">Der Name muss eindeutig sein.</span>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</dialog>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
