<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'KI-Schwellwerte, automatische Zuordnung und Fehlergründe';
ob_start();
?>
<div class="card">
    <div class="card-header">
        <h2>Clearing-Einstellungen</h2>
    </div>
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="clearing">
        <div class="form-row">
            <div class="form-group">
                <label for="confidence-threshold">KI-Konfidenzschwellwert</label>
                <input id="confidence-threshold" type="number" name="confidence_threshold"
                       min="0" max="1" step="0.05"
                       value="<?= e($settings->getString('clearing.confidence_threshold', '0.7')) ?>">
                <span class="form-hint">Dokumente mit einer Konfidenz unterhalb dieses Werts (0–1) werden ins Clearing verschoben.</span>
            </div>
            <div class="form-group">
                <label for="max-ai-attempts">Maximale Anzahl KI-Versuche</label>
                <input id="max-ai-attempts" type="number" name="max_ai_attempts" min="1" max="20" step="1"
                       value="<?= e($settings->getString('clearing.max_ai_attempts', '3')) ?>">
                <span class="form-hint">Begrenzung für manuelle und automatische Neuanalysen pro Dokument.</span>
            </div>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="auto_clearing_enabled" value="1"
                    <?= $settings->getBool('clearing.auto_clearing_enabled', true) ? 'checked' : '' ?>>
                Automatische Clearing-Zuordnung aktiv
            </label>
            <span class="form-hint">Nicht zuordenbare Dokumente werden automatisch ins Clearing verschoben statt als Fehler markiert.</span>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="allow_temporary_folders" value="1"
                    <?= $settings->getBool('clearing.allow_temporary_folders', true) ? 'checked' : '' ?>>
                Temporäre Patientenmappen erlauben
            </label>
            <span class="form-hint">Erlaubt das Anlegen von Patientenmappen ohne Fallnummer – die Fallnummer kann später nachgetragen werden.</span>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="auto_reanalysis" value="1"
                    <?= $settings->getBool('clearing.auto_reanalysis', false) ? 'checked' : '' ?>>
                Automatische Neuanalyse
            </label>
            <span class="form-hint">Startet nach dem Verschieben ins Clearing automatisch eine erneute KI-Analyse.</span>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2>Fehlergründe</h2>
        <button type="button" class="btn btn-primary btn-sm" data-dialog-open="error-reason-create-dialog">
            <svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>
            Neuer Fehlergrund
        </button>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Bezeichnung</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($errorReasons)): ?>
                    <tr><td colspan="4" class="table-empty">Keine Fehlergründe vorhanden.</td></tr>
                <?php endif; ?>
                <?php foreach ($errorReasons ?? [] as $reason): ?>
                    <tr>
                        <td><code><?= e($reason['code']) ?></code></td>
                        <td><?= e($reason['label']) ?></td>
                        <td>
                            <?php if ((int) $reason['is_active'] === 1): ?>
                                <span class="badge badge-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="btn btn-secondary btn-sm"
                                        data-edit-item="error-reason-edit-dialog"
                                        data-item='<?= e(json_encode(['id' => $reason['id'], 'label' => $reason['label'], 'is_active' => (int) $reason['is_active']], JSON_HEX_APOS)) ?>'>
                                    Bearbeiten
                                </button>
                                <form method="post" action="/admin/clearing-error-reasons"
                                      data-confirm="Fehlergrund „<?= e($reason['code']) ?>“ wirklich löschen?">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $reason['id'] ?>">
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

<dialog class="dialog" id="error-reason-create-dialog" aria-labelledby="error-reason-create-title">
    <form method="post" action="/admin/clearing-error-reasons">
        <div class="dialog-header">
            <h2 id="error-reason-create-title">Neuer Fehlergrund</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="error-reason-create-code">Code</label>
                <input id="error-reason-create-code" name="code" required pattern="[A-Z0-9_]+"
                       placeholder="z. B. MISSING_SIGNATURE" style="text-transform: uppercase;">
                <span class="form-hint">Nur Großbuchstaben, Ziffern und Unterstriche.</span>
            </div>
            <div class="form-group">
                <label for="error-reason-create-label">Bezeichnung</label>
                <input id="error-reason-create-label" name="label" required>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Anlegen</button>
        </div>
    </form>
</dialog>

<dialog class="dialog" id="error-reason-edit-dialog" aria-labelledby="error-reason-edit-title">
    <form method="post" action="/admin/clearing-error-reasons">
        <div class="dialog-header">
            <h2 id="error-reason-edit-title">Fehlergrund bearbeiten</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label for="error-reason-edit-label">Bezeichnung</label>
                <input id="error-reason-edit-label" name="label" required>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1">
                    Aktiv
                </label>
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
