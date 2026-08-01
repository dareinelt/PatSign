<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Vision- und Analysemodell konfigurieren';
ob_start();
?>
<div class="stack">
    <div class="card">
        <div class="card-header"><h2>Vision-Modell</h2></div>
        <form method="post" action="/admin/settings">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="section" value="ai">
            <div class="form-row">
                <div class="form-group">
                    <label for="vision-host">Host</label>
                    <input id="vision-host" name="vision_host" value="<?= e($settings->getString('ai.vision.host')) ?>">
                </div>
                <div class="form-group">
                    <label for="vision-port">Port</label>
                    <input id="vision-port" type="number" name="vision_port" min="1" max="65535" value="<?= e($settings->getString('ai.vision.port')) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="vision-api-key">API-Key</label>
                    <input id="vision-api-key" type="password" name="vision_api_key" value="<?= e($settings->getString('ai.vision.api_key')) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="vision-model">Modell</label>
                    <input id="vision-model" name="vision_model" value="<?= e($settings->getString('ai.vision.model')) ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="vision-timeout">Timeout (Sekunden)</label>
                <input id="vision-timeout" type="number" name="vision_timeout" min="1" max="600" value="<?= e($settings->getString('ai.vision.timeout')) ?>">
            </div>

            <div class="card-header mt-4"><h2>Analysemodell</h2></div>
            <div class="form-row">
                <div class="form-group">
                    <label for="analysis-host">Host</label>
                    <input id="analysis-host" name="analysis_host" value="<?= e($settings->getString('ai.analysis.host')) ?>">
                </div>
                <div class="form-group">
                    <label for="analysis-port">Port</label>
                    <input id="analysis-port" type="number" name="analysis_port" min="1" max="65535" value="<?= e($settings->getString('ai.analysis.port')) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="analysis-api-key">API-Key</label>
                    <input id="analysis-api-key" type="password" name="analysis_api_key" value="<?= e($settings->getString('ai.analysis.api_key')) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="analysis-model">Modell</label>
                    <input id="analysis-model" name="analysis_model" value="<?= e($settings->getString('ai.analysis.model')) ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="analysis-timeout">Timeout (Sekunden)</label>
                <input id="analysis-timeout" type="number" name="analysis_timeout" min="1" max="600" value="<?= e($settings->getString('ai.analysis.timeout')) ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Analyse-Prompt</h2></div>
        <p class="text-muted text-sm">Neue Prompt-Version anlegen. Ältere Versionen bleiben in der Datenbank erhalten.</p>
        <form method="post" action="/admin/prompts">
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
                <textarea id="prompt-content" name="content" rows="8" required></textarea>
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
