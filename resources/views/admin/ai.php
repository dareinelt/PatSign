<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Vision- und Analysemodell konfigurieren';
ob_start();
?>
<div class="stack" data-ai-settings data-csrf="<?= e($csrf) ?>">
    <div class="card">
        <div class="card-header"><h2>Vision-Modell</h2></div>
        <form method="post" action="/admin/settings">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="section" value="ai">
            <div class="form-row" data-ai-endpoint="vision">
                <div class="form-group">
                    <label for="vision-host">Host</label>
                    <input id="vision-host" name="vision_host" data-ai-field="host" value="<?= e($settings->getString('ai.vision.host')) ?>">
                </div>
                <div class="form-group">
                    <label for="vision-port">Port</label>
                    <input id="vision-port" type="number" name="vision_port" data-ai-field="port" min="1" max="65535" value="<?= e($settings->getString('ai.vision.port')) ?>">
                </div>
            </div>
            <div class="form-row" data-ai-endpoint="vision">
                <div class="form-group">
                    <label for="vision-api-key">API-Key</label>
                    <input id="vision-api-key" type="password" name="vision_api_key" data-ai-field="api_key" value="<?= e($settings->getString('ai.vision.api_key')) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="vision-model">Modell</label>
                    <select id="vision-model" name="vision_model" data-ai-field="model">
                        <?php if ($settings->getString('ai.vision.model') !== ''): ?>
                            <option value="<?= e($settings->getString('ai.vision.model')) ?>" selected><?= e($settings->getString('ai.vision.model')) ?></option>
                        <?php endif; ?>
                    </select>
                    <small class="text-muted text-sm" data-ai-models-status="vision"></small>
                </div>
            </div>
            <div class="form-group" data-ai-endpoint="vision">
                <label for="vision-timeout">Timeout (Sekunden)</label>
                <input id="vision-timeout" type="number" name="vision_timeout" data-ai-field="timeout" min="1" max="600" value="<?= e($settings->getString('ai.vision.timeout')) ?>">
            </div>
            <div class="form-actions">
                <button type="button" class="btn" data-ai-test="vision">Verbindung testen</button>
                <span class="text-sm" data-ai-test-status="vision"></span>
            </div>

            <div class="card-header mt-4"><h2>Analysemodell</h2></div>
            <div class="form-row" data-ai-endpoint="analysis">
                <div class="form-group">
                    <label for="analysis-host">Host</label>
                    <input id="analysis-host" name="analysis_host" data-ai-field="host" value="<?= e($settings->getString('ai.analysis.host')) ?>">
                </div>
                <div class="form-group">
                    <label for="analysis-port">Port</label>
                    <input id="analysis-port" type="number" name="analysis_port" data-ai-field="port" min="1" max="65535" value="<?= e($settings->getString('ai.analysis.port')) ?>">
                </div>
            </div>
            <div class="form-row" data-ai-endpoint="analysis">
                <div class="form-group">
                    <label for="analysis-api-key">API-Key</label>
                    <input id="analysis-api-key" type="password" name="analysis_api_key" data-ai-field="api_key" value="<?= e($settings->getString('ai.analysis.api_key')) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="analysis-model">Modell</label>
                    <select id="analysis-model" name="analysis_model" data-ai-field="model">
                        <?php if ($settings->getString('ai.analysis.model') !== ''): ?>
                            <option value="<?= e($settings->getString('ai.analysis.model')) ?>" selected><?= e($settings->getString('ai.analysis.model')) ?></option>
                        <?php endif; ?>
                    </select>
                    <small class="text-muted text-sm" data-ai-models-status="analysis"></small>
                </div>
            </div>
            <div class="form-group" data-ai-endpoint="analysis">
                <label for="analysis-timeout">Timeout (Sekunden)</label>
                <input id="analysis-timeout" type="number" name="analysis_timeout" data-ai-field="timeout" min="1" max="600" value="<?= e($settings->getString('ai.analysis.timeout')) ?>">
            </div>
            <div class="form-actions">
                <button type="button" class="btn" data-ai-test="analysis">Verbindung testen</button>
                <span class="text-sm" data-ai-test-status="analysis"></span>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Analyse-Prompt</h2></div>
        <p class="text-muted text-sm">Der aktive Prompt wird als Vorlage angezeigt. Beim Speichern wird eine neue Version angelegt; ältere Versionen bleiben in der Datenbank erhalten.</p>
        <form method="post" action="/admin/prompts" data-prompt-form data-prompts="<?= e(json_encode($activePrompts ?? [], JSON_THROW_ON_ERROR)) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="prompt-type">Typ</label>
                <select id="prompt-type" name="type">
                    <option value="vision">Vision</option>
                    <option value="analysis">Analyse</option>
                </select>
            </div>
            <div class="form-group">
                <label for="prompt-content">Prompt</label>
                <textarea id="prompt-content" name="content" rows="8" required><?= e($activePrompts['vision'] ?? '') ?></textarea>
            </div>
            <label class="checkbox-field">
                <input type="checkbox" name="activate" value="1" checked>
                <span>Sofort aktivieren</span>
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Prompt speichern</button>
            </div>
        </form>
    </div>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
