<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Benutzerkonten verwalten';
ob_start();
?>
<div class="card">
    <div class="card-header">
        <h2>Benutzer</h2>
        <button type="button" class="btn btn-primary btn-sm" data-dialog-open="user-create-dialog">
            <svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>
            Neuer Benutzer
        </button>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Benutzername</th>
                    <th scope="col">Rolle</th>
                    <th scope="col">Status</th>
                    <th scope="col">Angelegt</th>
                    <th scope="col" class="text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" class="table-empty">Keine Benutzer vorhanden.</td></tr>
                <?php endif; ?>
                <?php foreach ($users ?? [] as $account): ?>
                    <tr>
                        <td><?= e($account['username']) ?></td>
                        <td><span class="badge <?= $account['role_name'] === 'admin' ? 'badge-danger' : 'badge-info' ?>"><?= e($account['role_name']) ?></span></td>
                        <td>
                            <?php if ((int) $account['is_active'] === 1): ?>
                                <span class="badge badge-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Deaktiviert</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($account['created_at']) ?></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="btn btn-secondary btn-sm"
                                        data-edit-item="user-edit-dialog"
                                        data-item='<?= e(json_encode(['id' => $account['id'], 'username' => $account['username'], 'role_id' => $account['role_id'], 'is_active' => $account['is_active']], JSON_HEX_APOS)) ?>'>
                                    Bearbeiten
                                </button>
                                <form method="post" action="/admin/users" data-confirm="Benutzer „<?= e($account['username']) ?>“ wirklich löschen?">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
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

<dialog class="dialog" id="user-create-dialog" aria-labelledby="user-create-title">
    <form method="post" action="/admin/users">
        <div class="dialog-header">
            <h2 id="user-create-title">Neuer Benutzer</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="user-create-username">Benutzername</label>
                <input id="user-create-username" name="username" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="user-create-password">Passwort</label>
                <input id="user-create-password" type="password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="user-create-role">Rolle</label>
                <select id="user-create-role" name="role_id" required>
                    <?php foreach ($roles ?? [] as $role): ?>
                        <option value="<?= (int) $role['id'] ?>"><?= e($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="checkbox-field">
                <input type="checkbox" name="is_active" value="1" checked>
                <span>Konto aktiv</span>
            </label>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Anlegen</button>
        </div>
    </form>
</dialog>

<dialog class="dialog" id="user-edit-dialog" aria-labelledby="user-edit-title">
    <form method="post" action="/admin/users">
        <div class="dialog-header">
            <h2 id="user-edit-title">Benutzer bearbeiten</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label for="user-edit-username">Benutzername</label>
                <input id="user-edit-username" name="username" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="user-edit-password">Neues Passwort</label>
                <input id="user-edit-password" type="password" name="password" autocomplete="new-password">
                <span class="form-hint">Leer lassen, um das Passwort beizubehalten.</span>
            </div>
            <div class="form-group">
                <label for="user-edit-role">Rolle</label>
                <select id="user-edit-role" name="role_id" required>
                    <?php foreach ($roles ?? [] as $role): ?>
                        <option value="<?= (int) $role['id'] ?>"><?= e($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="checkbox-field">
                <input type="checkbox" name="is_active" value="1">
                <span>Konto aktiv</span>
            </label>
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
