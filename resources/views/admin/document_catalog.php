<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'PDF-Vorlagen mit Versionierung, Kategorien und Platzhaltern verwalten';
$isAdmin = ($user['role'] ?? '') === 'admin';
ob_start();
?>
<div class="card">
    <div class="card-header">
        <h2>Dokumentvorlagen</h2>
        <div class="table-actions">
            <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="catalog-categories-dialog">
                Kategorien
            </button>
            <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="catalog-placeholders-dialog">
                Platzhalter
            </button>
            <button type="button" class="btn btn-primary btn-sm" data-dialog-open="catalog-create-dialog">
                <svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>
                Neue Vorlage
            </button>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Bezeichnung</th>
                    <th scope="col">Dokumenttyp</th>
                    <th scope="col">Kategorie</th>
                    <th scope="col">Datei</th>
                    <th scope="col">Version</th>
                    <th scope="col">Status</th>
                    <th scope="col">Ersteller</th>
                    <th scope="col">Geändert</th>
                    <th scope="col">Verwendungen</th>
                    <th scope="col" class="text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($templates)): ?>
                    <tr><td colspan="10" class="table-empty">Keine Dokumentvorlagen vorhanden.</td></tr>
                <?php endif; ?>
                <?php foreach ($templates ?? [] as $template): ?>
                    <?php
                    $tplId = (int) $template['id'];
                    $isArchived = (int) $template['is_archived'] === 1;
                    $isActive = (int) $template['is_active'] === 1;
                    $usage = (int) ($template['usage_count'] ?? 0);
                    $tplPlaceholders = json_decode((string) ($template['current_placeholders_json'] ?? '[]'), true) ?: [];
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($template['name']) ?></strong>
                            <?php if (!empty($template['description'])): ?>
                                <div class="text-muted"><?= e($template['description']) ?></div>
                            <?php endif; ?>
                            <?php if ($tplPlaceholders !== []): ?>
                                <div class="text-muted">Platzhalter: <?= e(implode(', ', $tplPlaceholders)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($template['document_type']) ?></td>
                        <td><?= e($template['category_name'] ?? '–') ?></td>
                        <td><?= e($template['current_file_name'] ?? '–') ?></td>
                        <td>
                            v<?= (int) $template['current_version'] ?>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    data-dialog-open="catalog-versions-dialog-<?= $tplId ?>">Historie</button>
                        </td>
                        <td>
                            <?php if ($isArchived): ?>
                                <span class="badge badge-muted">Archiviert</span>
                            <?php elseif ($isActive): ?>
                                <span class="badge badge-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($template['created_by_name'] ?? '–') ?></td>
                        <td><?= e($template['updated_at'] ?? $template['created_at']) ?></td>
                        <td><?= $usage ?></td>
                        <td>
                            <div class="table-actions">
                                <a class="btn btn-secondary btn-sm" target="_blank"
                                   href="/admin/document-catalog/file?id=<?= $tplId ?>&mode=preview">Vorschau</a>
                                <a class="btn btn-secondary btn-sm"
                                   href="/admin/document-catalog/file?id=<?= $tplId ?>&mode=download">Download</a>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        data-edit-item="catalog-edit-dialog"
                                        data-item='<?= e(json_encode([
                                            'id' => $tplId,
                                            'name' => $template['name'],
                                            'description' => $template['description'] ?? '',
                                            'document_type' => $template['document_type'],
                                            'category_id' => $template['category_id'] ?? '',
                                        ], JSON_HEX_APOS)) ?>'>
                                    Bearbeiten
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        data-edit-item="catalog-replace-dialog"
                                        data-item='<?= e(json_encode(['id' => $tplId, 'name' => $template['name']], JSON_HEX_APOS)) ?>'>
                                    Ersetzen
                                </button>
                                <?php if (!$isArchived): ?>
                                    <form method="post" action="/admin/document-catalog/status">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="id" value="<?= $tplId ?>">
                                        <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <?= $isActive ? 'Deaktivieren' : 'Aktivieren' ?>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/document-catalog/status"
                                          data-confirm="Vorlage „<?= e($template['name']) ?>“ archivieren? Sie steht dem Personal danach nicht mehr zur Auswahl.">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="id" value="<?= $tplId ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <button type="submit" class="btn btn-secondary btn-sm">Archivieren</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="/admin/document-catalog/status">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="id" value="<?= $tplId ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="btn btn-secondary btn-sm">Wiederherstellen</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($usage === 0): ?>
                                    <form method="post" action="/admin/document-catalog/status"
                                          data-confirm="Vorlage „<?= e($template['name']) ?>“ endgültig löschen?">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="id" value="<?= $tplId ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="card">
    <div class="card-header"><h2>Einstellungen</h2></div>
    <form method="post" action="/admin/settings" class="card-body">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="document-catalog">
        <div class="form-group">
            <label for="catalog-placeholder-default">Ersatzwert für nicht befüllbare Platzhalter</label>
            <input id="catalog-placeholder-default" name="placeholder_default"
                   value="<?= e($settings->getString('catalog.placeholder_default', '________________')) ?>">
            <p class="form-hint">Wird eingesetzt, wenn für einen Platzhalter kein Wert vorliegt (z. B. Station).</p>
        </div>
        <label class="checkbox-label">
            <input type="checkbox" name="validation_enabled" value="1"
                <?= $settings->getBool('catalog.validation_enabled', false) ? 'checked' : '' ?>>
            Optionale KI-Validierung personalisierter Katalogdokumente aktivieren
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Neue Vorlage -->
<dialog class="dialog" id="catalog-create-dialog" aria-labelledby="catalog-create-title">
    <form method="post" action="/admin/document-catalog/save" enctype="multipart/form-data">
        <div class="dialog-header">
            <h2 id="catalog-create-title">Neue Dokumentvorlage</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="catalog-create-name">Bezeichnung</label>
                <input id="catalog-create-name" name="name" required>
            </div>
            <div class="form-group">
                <label for="catalog-create-description">Beschreibung (optional)</label>
                <textarea id="catalog-create-description" name="description" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label for="catalog-create-type">Dokumenttyp</label>
                <select id="catalog-create-type" name="document_type" required>
                    <?php foreach ($documentTypes ?? [] as $type): ?>
                        <option value="<?= e($type['name']) ?>"><?= e($type['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="catalog-create-category">Kategorie</label>
                <select id="catalog-create-category" name="category_id">
                    <option value="">– Keine –</option>
                    <?php foreach ($categories ?? [] as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="catalog-create-file">PDF-Datei</label>
                <input id="catalog-create-file" type="file" name="template_file" accept="application/pdf" required>
                <p class="form-hint">Platzhalter im Format {{PLATZHALTER}} werden beim Hochladen automatisch erkannt.</p>
            </div>
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" checked>
                Sofort für das Personal zur Auswahl freigeben
            </label>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Anlegen</button>
        </div>
    </form>
</dialog>

<!-- Vorlage bearbeiten -->
<dialog class="dialog" id="catalog-edit-dialog" aria-labelledby="catalog-edit-title">
    <form method="post" action="/admin/document-catalog/update">
        <div class="dialog-header">
            <h2 id="catalog-edit-title">Vorlage bearbeiten</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="">
            <div class="form-group">
                <label for="catalog-edit-name">Bezeichnung</label>
                <input id="catalog-edit-name" name="name" required>
            </div>
            <div class="form-group">
                <label for="catalog-edit-description">Beschreibung (optional)</label>
                <textarea id="catalog-edit-description" name="description" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label for="catalog-edit-type">Dokumenttyp</label>
                <select id="catalog-edit-type" name="document_type" required>
                    <?php foreach ($documentTypes ?? [] as $type): ?>
                        <option value="<?= e($type['name']) ?>"><?= e($type['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="catalog-edit-category">Kategorie</label>
                <select id="catalog-edit-category" name="category_id">
                    <option value="">– Keine –</option>
                    <?php foreach ($categories ?? [] as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</dialog>

<!-- Vorlage ersetzen (neue Version) -->
<dialog class="dialog" id="catalog-replace-dialog" aria-labelledby="catalog-replace-title">
    <form method="post" action="/admin/document-catalog/replace" enctype="multipart/form-data">
        <div class="dialog-header">
            <h2 id="catalog-replace-title">Vorlage ersetzen</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="">
            <p>Es wird eine neue Version angelegt. Bereits zu Patientenmappen hinzugefügte Dokumente
               bleiben unverändert und verweisen weiterhin auf die bisherige Version.</p>
            <div class="form-group">
                <label for="catalog-replace-file">Neue PDF-Datei</label>
                <input id="catalog-replace-file" type="file" name="template_file" accept="application/pdf" required>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary">Neue Version anlegen</button>
        </div>
    </form>
</dialog>

<!-- Versionshistorie je Vorlage -->
<?php foreach ($templates ?? [] as $template): ?>
    <?php $tplId = (int) $template['id']; ?>
    <dialog class="dialog" id="catalog-versions-dialog-<?= $tplId ?>" aria-labelledby="catalog-versions-title-<?= $tplId ?>">
        <div class="dialog-header">
            <h2 id="catalog-versions-title-<?= $tplId ?>">Versionshistorie: <?= e($template['name']) ?></h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
        </div>
        <div class="dialog-body">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Version</th>
                        <th scope="col">Datei</th>
                        <th scope="col">Hochgeladen</th>
                        <th scope="col">Von</th>
                        <th scope="col">Verwendungen</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templateVersions[$tplId] ?? [] as $version): ?>
                        <tr>
                            <td>
                                v<?= (int) $version['version'] ?>
                                <?= (int) $version['version'] === (int) $template['current_version'] ? ' (aktuell)' : '' ?>
                            </td>
                            <td><?= e($version['file_name']) ?></td>
                            <td><?= e($version['created_at']) ?></td>
                            <td><?= e($version['created_by_name'] ?? '–') ?></td>
                            <td><?= (int) ($version['usage_count'] ?? 0) ?></td>
                            <td>
                                <a class="btn btn-secondary btn-sm" target="_blank"
                                   href="/admin/document-catalog/file?id=<?= $tplId ?>&version=<?= (int) $version['version'] ?>&mode=preview">Vorschau</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Schließen</button>
        </div>
    </dialog>
<?php endforeach; ?>

<!-- Kategorien verwalten -->
<dialog class="dialog" id="catalog-categories-dialog" aria-labelledby="catalog-categories-title">
    <div class="dialog-header">
        <h2 id="catalog-categories-title">Kategorien verwalten</h2>
        <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
    </div>
    <div class="dialog-body">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Vorlagen</th>
                    <th scope="col" class="text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="3" class="table-empty">Keine Kategorien vorhanden.</td></tr>
                <?php endif; ?>
                <?php foreach ($categories ?? [] as $category): ?>
                    <tr>
                        <td><?= e($category['name']) ?></td>
                        <td><?= (int) ($category['template_count'] ?? 0) ?></td>
                        <td>
                            <div class="table-actions">
                                <form method="post" action="/admin/document-catalog/categories" class="form-inline">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                    <input name="name" value="<?= e($category['name']) ?>" required>
                                    <button type="submit" class="btn btn-secondary btn-sm">Umbenennen</button>
                                </form>
                                <?php if ((int) ($category['template_count'] ?? 0) === 0): ?>
                                    <form method="post" action="/admin/document-catalog/categories"
                                          data-confirm="Kategorie „<?= e($category['name']) ?>“ löschen?">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="/admin/document-catalog/categories" class="form-inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <input name="name" placeholder="Neue Kategorie" required>
            <button type="submit" class="btn btn-primary btn-sm">Anlegen</button>
        </form>
    </div>
    <div class="dialog-footer">
        <button type="button" class="btn btn-secondary" data-dialog-close>Schließen</button>
    </div>
</dialog>

<!-- Platzhalter-Hilfe -->
<dialog class="dialog" id="catalog-placeholders-dialog" aria-labelledby="catalog-placeholders-title">
    <div class="dialog-header">
        <h2 id="catalog-placeholders-title">Unterstützte Platzhalter</h2>
        <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Dialog schließen">✕</button>
    </div>
    <div class="dialog-body">
        <p>Platzhalter werden beim Hinzufügen zu einer Patientenmappe automatisch mit den Daten
           des Patienten befüllt. Format in der PDF-Vorlage: <code>{{PLATZHALTER}}</code>.</p>
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Platzhalter</th>
                    <th scope="col">Bedeutung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($placeholders ?? [] as $key => $label): ?>
                    <tr>
                        <td><code>{{<?= e($key) ?>}}</code></td>
                        <td><?= e($label) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="form-hint">Hinweis: Die Ersetzung funktioniert bei Vorlagen mit Standard-Schriftarten
           (WinAnsi-Kodierung). Werden beim Hochladen keine Platzhalter erkannt, obwohl welche enthalten
           sind, verwendet die Vorlage vermutlich eingebettete Subset-Schriften – in diesem Fall die PDF
           mit Standard-Schriftarten (z. B. Helvetica, Arial) neu erzeugen.</p>
    </div>
    <div class="dialog-footer">
        <button type="button" class="btn btn-secondary" data-dialog-close>Schließen</button>
    </div>
</dialog>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
