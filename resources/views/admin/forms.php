<?php
require_once __DIR__ . '/../partials/helpers.php';
$sectionSubtitle = 'Interaktive Formulare: Erkennung, Eingabe und Vorbelegung';
ob_start();
?>
<div class="card">
    <form method="post" action="/admin/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="section" value="forms">

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="analysis_enabled" value="1"
                    <?= $settings->getBool('forms.analysis_enabled', true) ? 'checked' : '' ?>>
                Formularanalyse aktivieren
            </label>
            <span class="form-hint">Erkennt ausfüllbare Dokumente (z. B. Anamnesebögen) automatisch während der KI-Analyse und analysiert deren Eingabefelder.</span>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="freehand_enabled" value="1"
                    <?= $settings->getBool('forms.freehand_enabled', false) ? 'checked' : '' ?>>
                Freies Schreiben mit dem Stift (Freihandmodus)
            </label>
            <span class="form-hint">Alternative zur Formularanalyse: Im Kiosk-Modus kann jederzeit auf dem gesamten Formular mit dem Apple Pencil geschrieben werden. Die Stifteingaben werden beim Unterschreiben in das Dokument übernommen. Formularfeld-Overlays werden dabei nicht angezeigt.</span>
        </div>

        <div class="form-group">
            <label for="forms-vision-model">Vision-Modell für Formularerkennung</label>
            <input id="forms-vision-model" name="vision_model" value="<?= e($settings->getString('forms.vision_model', '')) ?>" placeholder="Standard: Vision-Modell der KI-Einstellungen">
            <span class="form-hint">Optionales abweichendes Modell für die Erkennung von Eingabefeldern. Leer lassen, um das Vision-Modell aus den KI-Einstellungen zu verwenden.</span>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="required_check_enabled" value="1"
                    <?= $settings->getBool('forms.required_check_enabled', true) ? 'checked' : '' ?>>
                Pflichtfeldprüfung aktiv
            </label>
            <span class="form-hint">Patienten können erst fortfahren bzw. unterschreiben, wenn alle Pflichtfelder ausgefüllt sind.</span>
        </div>

        <div class="form-group">
            <label for="forms-autosave-interval">Autosave-Intervall (Sekunden)</label>
            <input id="forms-autosave-interval" type="number" name="autosave_interval" min="1" max="60" value="<?= e($settings->getString('forms.autosave_interval', '3')) ?>">
            <span class="form-hint">Wie schnell Eingaben automatisch zwischengespeichert werden (Standard: 3 Sekunden).</span>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="allow_handwriting" value="1"
                        <?= $settings->getBool('forms.allow_handwriting', true) ? 'checked' : '' ?>>
                    Handschrift erlauben (Apple Pencil)
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="allow_keyboard" value="1"
                        <?= $settings->getBool('forms.allow_keyboard', true) ? 'checked' : '' ?>>
                    Tastatureingabe erlauben
                </label>
            </div>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="prefill_enabled" value="1"
                    <?= $settings->getBool('forms.prefill_enabled', true) ? 'checked' : '' ?>>
                Vorbelegung aktivieren
            </label>
            <span class="form-hint">Bekannte Daten der Patientenmappe (Name, Geburtsdatum, Fallnummer, aktuelles Datum) werden automatisch eingetragen.</span>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="prefill_locked" value="1"
                    <?= $settings->getBool('forms.prefill_locked', false) ? 'checked' : '' ?>>
                Vorbelegte Felder sperren
            </label>
            <span class="form-hint">Automatisch ausgefüllte Felder können vom Patienten nicht mehr geändert werden.</span>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
    </form>
</div>
<?php
$adminContent = ob_get_clean();
include __DIR__ . '/admin_layout.php';
