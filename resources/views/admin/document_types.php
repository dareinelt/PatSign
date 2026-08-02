<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Dokumenttypen anlegen, umbenennen und löschen';
ob_start();
?>
<div class="card">
    <div class="card-header">
        <h2>Dokumenttypen</h2>
        <form method="post" action="/admin/settings" data-autosubmit>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="section" value="document-types">
            <label class="checkbox-label">
                <input type="checkbox" name="first_page_only" value="1"
                    <?= $settings->getBool('analysis.first_page_only', false) ? 'checked' : '' ?>>
                Nur Seite 1 zur KI-Erkennung und -Analyse senden
            </label>
        </form>
        <button type="button" class="btn btn-primary btn-sm" data-dialog-open="doc-type-create-dialog">
            <svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>
            Neuer Dokumenttyp
        </button>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Angelegt</th>
                    <th scope="col" class="text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documentTypes)): ?>
                    <tr><td colspan="3" class="table-empty">Keine Dokumenttypen vorhanden.</td></tr>
                <?php endif; ?>
                <?php foreach ($documentTypes ?? [] as $type): ?>
                    <tr>
                        <td><?= e($type['name']) ?></td>
                        <td><?= e($type['created_at']) ?></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="btn btn-secondary btn-sm"
                                        data-edit-item="doc-type-edit-dialog"
                                        data-item='<?= e(json_encode(['id' => $type['id'], 'name' => $type['name']], JSON_HEX_APOS)) ?>'>
                                    Bearbeiten
                                </button>
                                <form method="post" action="/admin/document-types" data-confirm="Dokumenttyp „<?= e($type['name']) ?>“ wirklich löschen?">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $type['id'] ?>">
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

<dialog class="dialog" id="doc-type-create-dialog" aria-labelledby="doc-type-create-title">
    <form method="post" action="/admin/document-types">
        <div class="dialog-header">
            <h2 id="doc-type-create-title">Neuer Dokumenttyp</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="doc-type-create-name">Name</label>
                <input id="doc-type-create-name" name="name" required>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Anlegen</button>
        </div>
    </form>
</dialog>

<dialog class="dialog" id="doc-type-edit-dialog" aria-labelledby="doc-type-edit-title">
    <form method="post" action="/admin/document-types">
        <div class="dialog-header">
            <h2 id="doc-type-edit-title">Dokumenttyp bearbeiten</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label for="doc-type-edit-name">Name</label>
                <input id="doc-type-edit-name" name="name" required>
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
