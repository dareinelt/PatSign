<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Rollen anlegen, umbenennen und löschen';
ob_start();
?>
<div class="card">
    <div class="card-header">
        <h2>Rollen</h2>
        <button type="button" class="btn btn-primary btn-sm" data-dialog-open="role-create-dialog">
            <svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>
            Neue Rolle
        </button>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Benutzer</th>
                    <th scope="col" class="text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($roles)): ?>
                    <tr><td colspan="3" class="table-empty">Keine Rollen vorhanden.</td></tr>
                <?php endif; ?>
                <?php foreach ($roles ?? [] as $role): ?>
                    <tr>
                        <td><?= e($role['name']) ?></td>
                        <td><?= (int) ($role['user_count'] ?? 0) ?></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="btn btn-secondary btn-sm"
                                        data-edit-item="role-edit-dialog"
                                        data-item='<?= e(json_encode(['id' => $role['id'], 'name' => $role['name']], JSON_HEX_APOS)) ?>'>
                                    Bearbeiten
                                </button>
                                <form method="post" action="/admin/roles" data-confirm="Rolle „<?= e($role['name']) ?>“ wirklich löschen?">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" <?= (int) ($role['user_count'] ?? 0) > 0 ? 'disabled' : '' ?>>Löschen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<dialog class="dialog" id="role-create-dialog" aria-labelledby="role-create-title">
    <form method="post" action="/admin/roles">
        <div class="dialog-header">
            <h2 id="role-create-title">Neue Rolle</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="role-create-name">Name</label>
                <input id="role-create-name" name="name" required>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Anlegen</button>
        </div>
    </form>
</dialog>

<dialog class="dialog" id="role-edit-dialog" aria-labelledby="role-edit-title">
    <form method="post" action="/admin/roles">
        <div class="dialog-header">
            <h2 id="role-edit-title">Rolle bearbeiten</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label for="role-edit-name">Name</label>
                <input id="role-edit-name" name="name" required>
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
