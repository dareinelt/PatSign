/* Transparente Eingabeebene über dem PDF-Viewer für interaktive Formulare.
   Die Felder werden mit relativen Koordinaten (0–1) prozentual über den
   Seiten-Canvas positioniert und skalieren dadurch automatisch mit.
   Alle Validierungen erfolgen serverseitig; die Ebene zeigt nur Ergebnisse an. */
(function () {
    "use strict";

    var UNIT_SEPARATOR = "\u001f";

    function el(tag, className) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        return node;
    }

    function isHandwriting(value) {
        return typeof value === "string" && value.indexOf("data:image/png;base64,") === 0;
    }

    /**
     * Erzeugt eine Overlay-Instanz.
     *
     * options:
     *   documentId       – Dokument-ID
     *   endpoints        – { structure, save, complete } (URLs, POST)
     *   csrf             – CSRF-Token (Patientenmodus) oder ""
     *   headers          – zusätzliche Header (z. B. Gerätetoken im Kioskmodus)
     *   onProgress(filledRequired, requiredTotal, complete)
     */
    function create(options) {
        var fields = [];
        var config = { autosave_interval: 3, allow_handwriting: true, allow_keyboard: true, required_check: true };
        var dirty = {};
        var saveTimer = null;
        var saving = false;
        var destroyed = false;
        var fieldNodes = {};

        function request(url, params) {
            var body = new URLSearchParams();
            body.set("document_id", String(options.documentId));
            if (options.csrf) {
                body.set("_csrf", options.csrf);
            }
            Object.keys(params || {}).forEach(function (key) {
                body.set(key, params[key]);
            });
            var headers = { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" };
            Object.keys(options.headers || {}).forEach(function (key) {
                headers[key] = options.headers[key];
            });
            return fetch(url, { method: "POST", headers: headers, body: body.toString() }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error(data.error || "Anfrage fehlgeschlagen");
                    }
                    return data;
                });
            });
        }

        function notifyProgress(data) {
            if (typeof options.onProgress === "function") {
                options.onProgress(
                    typeof data.filled_required === "number" ? data.filled_required : 0,
                    typeof data.required_total === "number" ? data.required_total : 0,
                    data.complete === true || data.valid === true
                );
            }
        }

        /* ------------------------------------------------------------ Autosave */

        function queueSave(uuid, value) {
            dirty[uuid] = value;
            if (saveTimer) {
                clearTimeout(saveTimer);
            }
            saveTimer = setTimeout(flush, Math.max(1, config.autosave_interval) * 1000);
        }

        function flush() {
            if (destroyed || saving || Object.keys(dirty).length === 0) {
                return Promise.resolve(null);
            }
            var payload = dirty;
            dirty = {};
            saving = true;
            return request(options.endpoints.save, { values: JSON.stringify(payload) })
                .then(function (data) {
                    applyErrors(data.errors || {});
                    notifyProgress(data);
                    return data;
                })
                .catch(function () {
                    // Bei Verbindungsabbruch Eingaben behalten und erneut versuchen.
                    Object.keys(payload).forEach(function (uuid) {
                        if (!(uuid in dirty)) {
                            dirty[uuid] = payload[uuid];
                        }
                    });
                    saveTimer = setTimeout(flush, 5000);
                    return null;
                })
                .finally(function () {
                    saving = false;
                    if (Object.keys(dirty).length > 0 && !saveTimer) {
                        saveTimer = setTimeout(flush, Math.max(1, config.autosave_interval) * 1000);
                    }
                });
        }

        function applyErrors(errors) {
            Object.keys(fieldNodes).forEach(function (uuid) {
                var node = fieldNodes[uuid];
                var message = errors[uuid] || "";
                node.classList.toggle("has-error", message !== "");
                var hint = node.querySelector(".form-overlay-error");
                if (hint) {
                    hint.textContent = message;
                    hint.classList.toggle("hidden", message === "");
                }
            });
        }

        /* ------------------------------------------------------- Handschrift */

        function openHandwriting(field, applyValue) {
            var backdrop = el("div", "form-overlay-modal-backdrop");
            var modal = el("div", "form-overlay-modal");
            var title = el("p", "form-overlay-modal-title");
            title.textContent = field.label || "Bitte schreiben Sie hier";
            var canvas = el("canvas", "form-overlay-handwriting-canvas");
            var actions = el("div", "form-overlay-modal-actions");
            var clearBtn = el("button", "btn btn-secondary");
            clearBtn.type = "button";
            clearBtn.textContent = "Löschen";
            var cancelBtn = el("button", "btn btn-secondary");
            cancelBtn.type = "button";
            cancelBtn.textContent = "Abbrechen";
            var okBtn = el("button", "btn btn-primary");
            okBtn.type = "button";
            okBtn.textContent = "Übernehmen";
            actions.appendChild(clearBtn);
            actions.appendChild(cancelBtn);
            actions.appendChild(okBtn);
            modal.appendChild(title);
            modal.appendChild(canvas);
            modal.appendChild(actions);
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);

            var ratio = window.devicePixelRatio || 1;
            var rect;
            var ctx = canvas.getContext("2d");
            var drawn = false;
            var drawing = false;

            function resize() {
                rect = canvas.getBoundingClientRect();
                canvas.width = rect.width * ratio;
                canvas.height = rect.height * ratio;
                ctx.scale(ratio, ratio);
                ctx.lineWidth = 2.5;
                ctx.lineCap = "round";
                ctx.lineJoin = "round";
                ctx.strokeStyle = "#1a2733";
            }
            resize();

            canvas.addEventListener("pointerdown", function (event) {
                event.preventDefault();
                drawing = true;
                canvas.setPointerCapture(event.pointerId);
                ctx.beginPath();
                ctx.moveTo(event.clientX - rect.left, event.clientY - rect.top);
            });
            canvas.addEventListener("pointermove", function (event) {
                if (!drawing) {
                    return;
                }
                ctx.lineTo(event.clientX - rect.left, event.clientY - rect.top);
                ctx.stroke();
                drawn = true;
            });
            ["pointerup", "pointercancel"].forEach(function (type) {
                canvas.addEventListener(type, function () {
                    drawing = false;
                });
            });

            function close() {
                backdrop.remove();
            }
            clearBtn.addEventListener("click", function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawn = false;
            });
            cancelBtn.addEventListener("click", close);
            okBtn.addEventListener("click", function () {
                if (drawn) {
                    applyValue(canvas.toDataURL("image/png"));
                }
                close();
            });
        }

        /* --------------------------------------------------- Feld-Darstellung */

        function position(node, field) {
            node.style.left = (field.x * 100) + "%";
            node.style.top = (field.y * 100) + "%";
            node.style.width = (field.width * 100) + "%";
            node.style.height = (field.height * 100) + "%";
        }

        function baseNode(field) {
            var node = el("div", "form-overlay-field form-overlay-type-" + field.type);
            node.setAttribute("data-field-uuid", field.uuid);
            if (field.required) {
                node.classList.add("is-required");
            }
            position(node, field);
            var hint = el("p", "form-overlay-error hidden");
            hint.setAttribute("role", "alert");
            node.appendChild(hint);
            return node;
        }

        function inputAria(input, field) {
            if (field.label) {
                input.setAttribute("aria-label", field.label);
            }
            if (field.required) {
                input.setAttribute("aria-required", "true");
            }
        }

        function textInput(field) {
            var node = baseNode(field);
            var multiline = field.type === "textarea";
            var input = el(multiline ? "textarea" : "input", "form-overlay-input");
            if (!multiline) {
                input.type = { number: "number", date: "date", time: "time", phone: "tel", email: "email" }[field.type] || "text";
            }
            inputAria(input, field);
            if (field.locked) {
                input.readOnly = true;
                node.classList.add("is-locked");
            }

            function setHandwritingPreview(value) {
                node.classList.toggle("has-handwriting", isHandwriting(value));
                node.style.backgroundImage = isHandwriting(value) ? "url(" + value + ")" : "";
                input.classList.toggle("hidden", isHandwriting(value));
            }

            if (isHandwriting(field.value)) {
                setHandwritingPreview(field.value);
            } else if (field.value !== null && field.value !== undefined) {
                input.value = field.value;
            }

            var textual = ["text", "textarea", "initials"].indexOf(field.type) >= 0;
            var handwriting = textual && config.allow_handwriting && !field.locked;
            var keyboard = !textual || config.allow_keyboard || field.locked;

            if (!keyboard) {
                input.readOnly = true;
                input.setAttribute("inputmode", "none");
            }
            input.addEventListener("input", function () {
                queueSave(field.uuid, input.value);
            });
            input.addEventListener("blur", function () {
                flush();
            });

            if (handwriting) {
                var penBtn = el("button", "form-overlay-pen");
                penBtn.type = "button";
                penBtn.setAttribute("aria-label", "Handschriftlich ausfüllen");
                penBtn.textContent = "\u270E";
                penBtn.addEventListener("click", function () {
                    openHandwriting(field, function (dataUrl) {
                        setHandwritingPreview(dataUrl);
                        queueSave(field.uuid, dataUrl);
                        flush();
                    });
                });
                node.appendChild(penBtn);
                if (!keyboard) {
                    input.addEventListener("pointerdown", function (event) {
                        event.preventDefault();
                        penBtn.click();
                    });
                }
            }

            node.insertBefore(input, node.firstChild);
            return node;
        }

        function signatureField(field) {
            var node = baseNode(field);
            node.classList.add("is-signature");
            var button = el("button", "form-overlay-signature-button");
            button.type = "button";
            button.textContent = field.type === "initials" ? "Initialen" : "Hier unterschreiben";
            inputAria(button, field);

            function preview(value) {
                if (isHandwriting(value)) {
                    node.style.backgroundImage = "url(" + value + ")";
                    node.classList.add("has-handwriting");
                    button.classList.add("is-filled");
                    button.textContent = "";
                }
            }
            preview(field.value);

            button.addEventListener("click", function () {
                openHandwriting(field, function (dataUrl) {
                    preview(dataUrl);
                    queueSave(field.uuid, dataUrl);
                    flush();
                });
            });
            node.insertBefore(button, node.firstChild);
            return node;
        }

        function checkboxField(field) {
            var node = baseNode(field);
            var input = el("input", "form-overlay-checkbox");
            input.type = "checkbox";
            inputAria(input, field);
            input.checked = field.value === "1" || field.value === "true";
            if (field.locked) {
                input.disabled = true;
            }
            input.addEventListener("change", function () {
                queueSave(field.uuid, input.checked ? "1" : "0");
                flush();
            });
            node.insertBefore(input, node.firstChild);
            return node;
        }

        function choiceField(field) {
            // Ja/Nein und Radio: nebeneinanderliegende Auswahlknöpfe.
            var node = baseNode(field);
            var group = el("div", "form-overlay-choice-group");
            group.setAttribute("role", "radiogroup");
            if (field.label) {
                group.setAttribute("aria-label", field.label);
            }
            var current = field.value;
            (field.options || []).forEach(function (option) {
                var button = el("button", "form-overlay-choice");
                button.type = "button";
                button.textContent = option;
                button.setAttribute("role", "radio");
                button.setAttribute("aria-checked", current === option ? "true" : "false");
                button.classList.toggle("is-selected", current === option);
                button.addEventListener("click", function () {
                    if (field.locked) {
                        return;
                    }
                    current = option;
                    Array.prototype.forEach.call(group.children, function (child) {
                        child.classList.toggle("is-selected", child === button);
                        child.setAttribute("aria-checked", child === button ? "true" : "false");
                    });
                    queueSave(field.uuid, option);
                    flush();
                });
                group.appendChild(button);
            });
            node.insertBefore(group, node.firstChild);
            return node;
        }

        function dropdownField(field) {
            var node = baseNode(field);
            var select = el("select", "form-overlay-input");
            inputAria(select, field);
            var placeholder = el("option");
            placeholder.value = "";
            placeholder.textContent = "Bitte wählen …";
            select.appendChild(placeholder);
            (field.options || []).forEach(function (option) {
                var opt = el("option");
                opt.value = option;
                opt.textContent = option;
                select.appendChild(opt);
            });
            if (field.value) {
                select.value = field.value;
            }
            if (field.locked) {
                select.disabled = true;
            }
            select.addEventListener("change", function () {
                queueSave(field.uuid, select.value);
                flush();
            });
            node.insertBefore(select, node.firstChild);
            return node;
        }

        function multiselectField(field) {
            var node = baseNode(field);
            var group = el("div", "form-overlay-choice-group");
            if (field.label) {
                group.setAttribute("aria-label", field.label);
            }
            var selected = {};
            if (typeof field.value === "string" && field.value !== "") {
                field.value.split(field.value.indexOf(UNIT_SEPARATOR) >= 0 ? UNIT_SEPARATOR : ",").forEach(function (part) {
                    selected[part.trim()] = true;
                });
            }
            (field.options || []).forEach(function (option) {
                var button = el("button", "form-overlay-choice");
                button.type = "button";
                button.textContent = option;
                button.setAttribute("aria-pressed", selected[option] ? "true" : "false");
                button.classList.toggle("is-selected", !!selected[option]);
                button.addEventListener("click", function () {
                    if (field.locked) {
                        return;
                    }
                    selected[option] = !selected[option];
                    button.classList.toggle("is-selected", selected[option]);
                    button.setAttribute("aria-pressed", selected[option] ? "true" : "false");
                    var parts = (field.options || []).filter(function (o) { return selected[o]; });
                    queueSave(field.uuid, parts.join(UNIT_SEPARATOR));
                    flush();
                });
                group.appendChild(button);
            });
            node.insertBefore(group, node.firstChild);
            return node;
        }

        var renderers = {
            text: textInput,
            textarea: textInput,
            number: textInput,
            date: textInput,
            time: textInput,
            phone: textInput,
            email: textInput,
            initials: textInput,
            yesno: choiceField,
            radio: choiceField,
            checkbox: checkboxField,
            dropdown: dropdownField,
            multiselect: multiselectField,
            signature: signatureField
        };

        /* ------------------------------------------------------------- API */

        function load() {
            return request(options.endpoints.structure, {}).then(function (data) {
                fields = data.fields || [];
                config = data.config || config;
                var requiredTotal = fields.filter(function (f) { return f.required; }).length;
                var filledRequired = fields.filter(function (f) {
                    return f.required && f.value !== null && f.value !== undefined && String(f.value).trim() !== "";
                }).length;
                notifyProgress({ filled_required: filledRequired, required_total: requiredTotal, complete: filledRequired >= requiredTotal });
                return data;
            });
        }

        function attachPage(pageNumber, wrapper) {
            fields.forEach(function (field) {
                if (field.page !== pageNumber || fieldNodes[field.uuid]) {
                    return;
                }
                var renderer = renderers[field.type] || textInput;
                var node = renderer(field);
                fieldNodes[field.uuid] = node;
                wrapper.appendChild(node);
            });
        }

        function complete() {
            return flush().then(function () {
                return request(options.endpoints.complete, {});
            }).then(function (data) {
                applyErrors(data.errors || {});
                notifyProgress(data);
                return data;
            });
        }

        function destroy() {
            destroyed = true;
            if (saveTimer) {
                clearTimeout(saveTimer);
                saveTimer = null;
            }
            fieldNodes = {};
        }

        return { load: load, attachPage: attachPage, complete: complete, flush: flush, destroy: destroy };
    }

    window.PatSignFormOverlay = { create: create };
})();
