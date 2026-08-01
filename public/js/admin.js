/* Administration: kleine Interaktionshilfen */
(function () {
    "use strict";

    // Flash-Meldungen als Toast anzeigen
    const flash = document.querySelector("[data-flash]");
    if (flash) {
        window.PatSignUI.toast(flash.getAttribute("data-flash"), flash.getAttribute("data-flash-type") || "success");
    }

    // Bearbeiten-Buttons füllen das Formular im Dialog
    document.addEventListener("click", function (event) {
        const editButton = event.target.closest("[data-edit-item]");
        if (!editButton) {
            return;
        }

        const dialog = document.getElementById(editButton.getAttribute("data-edit-item"));
        if (!dialog) {
            return;
        }

        const data = JSON.parse(editButton.getAttribute("data-item") || "{}");
        Object.keys(data).forEach(function (key) {
            const field = dialog.querySelector('[name="' + key + '"]');
            if (!field) {
                return;
            }
            if (field.type === "checkbox") {
                field.checked = data[key] === 1 || data[key] === "1" || data[key] === true;
            } else {
                field.value = data[key] == null ? "" : data[key];
            }
        });

        dialog.showModal();
    });

    // KI-Einstellungen: Modelle vom Endpunkt laden + Verbindungstest
    const aiSettings = document.querySelector("[data-ai-settings]");
    if (aiSettings) {
        const csrf = aiSettings.getAttribute("data-csrf");

        function fieldValue(type, field) {
            const input = document.querySelector('[id="' + type + '-' + field.replace("_", "-") + '"]');
            return input ? input.value.trim() : "";
        }

        function endpointParams(type) {
            const params = new URLSearchParams();
            params.set("_csrf", csrf);
            params.set("host", fieldValue(type, "host"));
            params.set("port", fieldValue(type, "port"));
            params.set("api_key", fieldValue(type, "api_key"));
            params.set("model", fieldValue(type, "model"));
            params.set("timeout", fieldValue(type, "timeout") || "10");
            return params;
        }

        function loadModels(type) {
            const status = document.querySelector('[data-ai-models-status="' + type + '"]');
            const datalist = document.getElementById(type + "-model-list");
            if (!fieldValue(type, "host")) {
                return;
            }
            if (status) {
                status.textContent = "Lade Modelle …";
            }

            fetch("/admin/ai/models", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: endpointParams(type).toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    const models = data.models || [];
                    if (datalist) {
                        datalist.innerHTML = "";
                        models.forEach(function (model) {
                            const option = document.createElement("option");
                            option.value = model;
                            datalist.appendChild(option);
                        });
                    }
                    if (status) {
                        status.textContent = models.length
                            ? models.length + " Modell(e) verfügbar"
                            : "Keine Modelle gefunden";
                    }
                })
                .catch(function (error) {
                    if (status) {
                        status.textContent = "Modelle konnten nicht geladen werden: " + error.message;
                    }
                });
        }

        ["vision", "analysis"].forEach(function (type) {
            ["host", "port", "api-key"].forEach(function (field) {
                const input = document.getElementById(type + "-" + field);
                if (input) {
                    input.addEventListener("change", function () { loadModels(type); });
                }
            });
            const modelInput = document.getElementById(type + "-model");
            if (modelInput) {
                modelInput.addEventListener("focus", function () {
                    const datalist = document.getElementById(type + "-model-list");
                    if (datalist && datalist.children.length === 0) {
                        loadModels(type);
                    }
                });
            }
            // Modelle initial laden, wenn ein Host konfiguriert ist
            loadModels(type);
        });

        document.querySelectorAll("[data-ai-test]").forEach(function (button) {
            button.addEventListener("click", function () {
                const type = button.getAttribute("data-ai-test");
                const status = document.querySelector('[data-ai-test-status="' + type + '"]');
                button.disabled = true;
                if (status) {
                    status.textContent = "Teste Verbindung …";
                    status.style.color = "";
                }

                fetch("/admin/ai/test", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: endpointParams(type).toString()
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        const success = data.success === true;
                        if (status) {
                            status.textContent = data.message || (success ? "Verbindung erfolgreich." : "Test fehlgeschlagen.");
                            status.style.color = success ? "var(--color-success, #15803d)" : "var(--color-danger, #b91c1c)";
                        }
                        if (window.PatSignUI) {
                            window.PatSignUI.toast(status ? status.textContent : "", success ? "success" : "error");
                        }
                    })
                    .catch(function (error) {
                        if (status) {
                            status.textContent = "Test fehlgeschlagen: " + error.message;
                            status.style.color = "var(--color-danger, #b91c1c)";
                        }
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
            });
        });
    }
})();
