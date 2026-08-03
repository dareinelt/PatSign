/* Dashboard: Live-Suche und Aktualisierung */
(function () {
    "use strict";

    const searchInput = document.getElementById("dashboard-search");
    const resultsList = document.getElementById("dashboard-search-results");
    const refreshButton = document.getElementById("dashboard-refresh");

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = String(value == null ? "" : value);
        return div.innerHTML;
    }

    /* Live-Suche mit kurzer Verzögerung */
    if (searchInput && resultsList) {
        let timer = null;

        searchInput.addEventListener("input", function () {
            window.clearTimeout(timer);
            const term = searchInput.value.trim();

            if (term.length < 2) {
                resultsList.hidden = true;
                resultsList.innerHTML = "";
                return;
            }

            timer = window.setTimeout(function () {
                fetch("/dashboard/search?q=" + encodeURIComponent(term), { headers: { Accept: "application/json" } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        const results = data.results || [];
                        if (results.length === 0) {
                            resultsList.innerHTML = '<li><span class="search-result"><span class="result-title">Keine Treffer</span></span></li>';
                        } else {
                            resultsList.innerHTML = results.map(function (row) {
                                const name = ((row.last_name || "") + ", " + (row.first_name || "")).replace(/^, |, $/g, "") || "Unbekannt";
                                return '<li><span class="search-result">' +
                                    '<span class="result-title">' + escapeHtml(name) + "</span>" +
                                    '<span class="result-meta">Fallnummer ' + escapeHtml(row.case_number || "–") +
                                    " · " + escapeHtml(row.document_type || "") +
                                    " · Status: " + escapeHtml(row.status || "") + "</span>" +
                                    "</span></li>";
                            }).join("");
                        }
                        resultsList.hidden = false;
                    })
                    .catch(function () {
                        window.PatSignUI.toast("Suche fehlgeschlagen", "error");
                    });
            }, 250);
        });

        document.addEventListener("click", function (event) {
            if (!event.target.closest(".search-box")) {
                resultsList.hidden = true;
            }
        });

        searchInput.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                resultsList.hidden = true;
            }
        });
    }

    if (refreshButton) {
        refreshButton.addEventListener("click", function () {
            window.location.reload();
        });
    }

    const focusSearch = document.getElementById("dashboard-focus-search");
    if (focusSearch && searchInput) {
        focusSearch.addEventListener("click", function () {
            searchInput.focus();
        });
    }

    /* Patientenmappe an ein Signaturgerät senden */
    const sendDialog = document.getElementById("send-to-device-dialog");
    const sendForm = document.getElementById("send-to-device-form");
    const deviceSelect = document.getElementById("send-device-select");

    const availabilityLabels = {
        free: "Frei",
        busy: "Belegt",
        offline: "Offline",
        locked: "Gesperrt",
        retired: "Außer Betrieb"
    };

    function loadFreeDevices() {
        if (!deviceSelect) {
            return;
        }
        deviceSelect.innerHTML = '<option value="">Geräte werden geladen …</option>';
        fetch("/devices/overview", { headers: { Accept: "application/json" } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                const devices = data.devices || [];
                const free = devices.filter(function (d) { return d.availability === "free"; });
                if (free.length === 0) {
                    deviceSelect.innerHTML = '<option value="">Kein freies Gerät verfügbar</option>';
                    return;
                }
                deviceSelect.innerHTML = '<option value="">Bitte Gerät wählen …</option>' + free.map(function (d) {
                    return '<option value="' + d.id + '">' + escapeHtml(d.name) + " (" + (availabilityLabels[d.availability] || d.availability) + ")</option>";
                }).join("");
            })
            .catch(function () {
                deviceSelect.innerHTML = '<option value="">Geräte konnten nicht geladen werden</option>';
            });
    }

    document.addEventListener("click", function (event) {
        const trigger = event.target.closest("[data-send-to-device]");
        if (!trigger || !sendDialog) {
            return;
        }
        const caseInput = document.getElementById("send-device-case-number");
        const info = document.getElementById("send-device-patient-info");
        if (caseInput) {
            caseInput.value = trigger.getAttribute("data-case-number") || "";
        }
        if (info) {
            info.textContent = (trigger.getAttribute("data-patient-name") || "Unbekannt") +
                " · Fallnummer " + (trigger.getAttribute("data-case-number") || "–");
        }
        loadFreeDevices();
        sendDialog.showModal();
    });

    if (sendForm) {
        sendForm.addEventListener("submit", function (event) {
            event.preventDefault();
            const submit = document.getElementById("send-device-submit");
            const body = new URLSearchParams(new FormData(sendForm));

            if (!body.get("device_id")) {
                window.PatSignUI.toast("Bitte ein freies Gerät auswählen.", "error");
                return;
            }

            submit.disabled = true;
            fetch("/devices/assign", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.data.error || "Senden fehlgeschlagen");
                    }
                    window.PatSignUI.toast(result.data.message || "Patientenmappe gesendet.", "success");
                    sendDialog.close();
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                })
                .finally(function () {
                    submit.disabled = false;
                });
        });
    }
    /* Overlay: Patientenmappen mit offenen Unterschriften */
    const foldersButton = document.getElementById("open-folders-button");
    const foldersList = document.getElementById("patient-folders-list");
    const foldersPeriod = document.getElementById("patient-folders-period");
    const foldersStartForm = document.getElementById("patient-folders-start-form");
    const foldersStartCase = document.getElementById("patient-folders-start-case");
    const foldersManualButton = document.getElementById("patient-folders-manual");

    const documentStatusLabels = {
        imported: "Importiert",
        analyzing: "KI wird ausgeführt",
        analyzed: "Zuordnung erfolgreich",
        ready: "Bereit zur Unterschrift",
        signed: "Unterschrieben",
        sent: "Versendet",
        archived: "Archiviert",
        error: "Fehler",
        clearing: "Clearing"
    };
    const signedStatuses = ["signed", "sent", "archived"];

    const formStatusLabels = {
        detected: "Formular erkannt",
        analyzed: "Formular analysiert",
        partial: "Formular teilweise ausgefüllt",
        complete: "Formular vollständig",
        signed: "Formular unterschrieben"
    };

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, "&quot;");
    }

    function renderFolders(data) {
        const folders = data.folders || [];

        if (foldersPeriod) {
            foldersPeriod.textContent = "Zeitraum: letzte " + (data.periodHours || 24) +
                " Stunden · " + folders.length + " Mappe(n) mit offenen Unterschriften";
        }

        if (folders.length === 0) {
            foldersList.innerHTML = '<p class="table-empty mb-0">Keine Patientenmappen mit offenen Unterschriften im gewählten Zeitraum.</p>';
            return;
        }

        foldersList.innerHTML = folders.map(function (folder) {
            const name = ((folder.last_name || "") + ", " + (folder.first_name || "")).replace(/^, |, $/g, "") || "Unbekannt";
            const openCount = (folder.document_count || 0) - (folder.done_count || 0);

            const documentsHtml = (folder.documents || []).map(function (doc) {
                const isSigned = signedStatuses.indexOf(doc.status) !== -1;
                const isError = doc.status === "error";
                const badgeClass = isSigned ? "badge-success" : (isError ? "badge-danger" : "badge-warning");
                const formLabel = formStatusLabels[doc.form_status] || "";
                const formBadge = formLabel !== ""
                    ? ' <span class="badge ' + (doc.form_status === "signed" || doc.form_status === "complete" ? "badge-success" : "badge-info") + '">' + escapeHtml(formLabel) + "</span>"
                    : "";
                const controls = '<span class="folder-document-controls">' +
                    '<button type="button" class="btn btn-ghost btn-sm" data-doc-move="up" title="Nach oben" aria-label="Dokument nach oben verschieben">▲</button>' +
                    '<button type="button" class="btn btn-ghost btn-sm" data-doc-move="down" title="Nach unten" aria-label="Dokument nach unten verschieben">▼</button>' +
                    (!isSigned
                        ? '<button type="button" class="btn btn-ghost btn-sm" data-doc-remove data-doc-name="' + escapeAttr(doc.document_type || "Dokument") + '" title="Aus Mappe entfernen" aria-label="Dokument aus Mappe entfernen">✕</button>'
                        : "") +
                    "</span>";
                return '<li class="folder-document" draggable="true" data-doc-id="' + (doc.id || 0) + '">' +
                    '<span class="folder-document-mark" aria-hidden="true">' + (isSigned ? "✓" : "○") + "</span>" +
                    '<span class="folder-document-type">' + escapeHtml(doc.document_type || "Dokument") + "</span>" +
                    '<span class="badge ' + badgeClass + '">' + escapeHtml(documentStatusLabels[doc.status] || doc.status) + "</span>" +
                    formBadge +
                    controls +
                    "</li>";
            }).join("");

            return '<div class="patient-row folder-row" data-folder-case="' + escapeAttr(folder.case_number || "") + '">' +
                "<div>" +
                '<div class="patient-name">' + escapeHtml(name) + "</div>" +
                '<div class="patient-meta">Fallnummer ' + escapeHtml(folder.case_number || "–") +
                " · " + (folder.document_count || 0) + " Dokument(e), davon " + openCount + " offen</div>" +
                '<ul class="folder-documents" data-reorder-case="' + escapeAttr(folder.case_number || "") + '">' + documentsHtml + "</ul>" +
                "</div>" +
                '<div class="patient-actions">' +
                '<button type="button" class="btn btn-primary btn-sm" data-folder-start="' + escapeAttr(folder.case_number || "") + '">Patientenmodus</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" data-send-to-device data-case-number="' + escapeAttr(folder.case_number || "") + '" data-patient-name="' + escapeAttr(name) + '">An Gerät senden</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" data-catalog-add data-case-number="' + escapeAttr(folder.case_number || "") + '" data-patient-name="' + escapeAttr(name) + '">Dokument hinzufügen</button>' +
                "</div>" +
                "</div>";
        }).join("");
    }

    function loadFolders() {
        if (!foldersList) {
            return;
        }
        foldersList.innerHTML = '<p class="table-empty mb-0">Patientenmappen werden geladen …</p>';
        fetch("/dashboard/folders", { headers: { Accept: "application/json" } })
            .then(function (response) { return response.json(); })
            .then(renderFolders)
            .catch(function () {
                foldersList.innerHTML = '<p class="table-empty mb-0">Patientenmappen konnten nicht geladen werden.</p>';
            });
    }

    if (foldersButton) {
        foldersButton.addEventListener("click", loadFolders);
    }

    if (foldersList && foldersStartForm && foldersStartCase) {
        foldersList.addEventListener("click", function (event) {
            const startTrigger = event.target.closest("[data-folder-start]");
            if (startTrigger) {
                foldersStartCase.value = startTrigger.getAttribute("data-folder-start") || "";
                foldersStartForm.submit();
            }
        });
    }

    if (foldersManualButton) {
        foldersManualButton.addEventListener("click", function () {
            window.PatSignUI.closeDialog("patient-folders-dialog");
            window.PatSignUI.openDialog("patient-start-dialog");
        });
    }

    /* Dokumentenkatalog: Dokumente hinzufügen, entfernen, Reihenfolge ändern */
    const catalogDialog = document.getElementById("catalog-add-dialog");
    const catalogList = document.getElementById("catalog-add-list");
    const catalogSearch = document.getElementById("catalog-add-search");
    const catalogCategory = document.getElementById("catalog-add-category");
    const catalogSubmit = document.getElementById("catalog-add-submit");
    const catalogPatient = document.getElementById("catalog-add-patient");
    const foldersCsrf = foldersList ? (foldersList.getAttribute("data-csrf") || "") : "";
    let catalogCase = "";
    let catalogSearchTimer = null;

    function postForm(url, params) {
        params.set("_csrf", foldersCsrf);
        return fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
            body: params.toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error(data.error || "Aktion fehlgeschlagen");
                }
                return data;
            });
        });
    }

    function updateCatalogSubmit() {
        if (!catalogSubmit || !catalogList) {
            return;
        }
        const count = catalogList.querySelectorAll("input[data-template-id]:checked").length;
        catalogSubmit.disabled = count === 0;
        catalogSubmit.textContent = count > 1 ? "Hinzufügen (" + count + ")" : "Hinzufügen";
    }

    function renderCatalogTemplates(data) {
        const templates = data.templates || [];

        if (catalogCategory && catalogCategory.options.length <= 1) {
            (data.categories || []).forEach(function (category) {
                const option = document.createElement("option");
                option.value = String(category.id);
                option.textContent = category.name;
                catalogCategory.appendChild(option);
            });
        }

        if (templates.length === 0) {
            catalogList.innerHTML = '<p class="table-empty mb-0">Keine passenden Vorlagen gefunden.</p>';
            updateCatalogSubmit();
            return;
        }

        catalogList.innerHTML = templates.map(function (template) {
            const description = template.description
                ? '<div class="patient-meta">' + escapeHtml(template.description) + "</div>"
                : "";
            return '<label class="patient-row folder-row catalog-template-row">' +
                '<input type="checkbox" data-template-id="' + (template.id || 0) + '">' +
                "<div>" +
                '<div class="patient-name">' + escapeHtml(template.name || "") + "</div>" +
                description +
                '<div class="patient-meta">' + escapeHtml(template.document_type || "") +
                (template.category_name ? " · " + escapeHtml(template.category_name) : "") +
                " · v" + (template.current_version || 1) + "</div>" +
                "</div>" +
                '<div class="patient-actions">' +
                '<a class="btn btn-secondary btn-sm" target="_blank" href="/catalog/preview?id=' + (template.id || 0) + '">Vorschau</a>' +
                "</div>" +
                "</label>";
        }).join("");
        updateCatalogSubmit();
    }

    function loadCatalogTemplates() {
        if (!catalogList) {
            return;
        }
        const params = new URLSearchParams();
        if (catalogSearch && catalogSearch.value.trim() !== "") {
            params.set("q", catalogSearch.value.trim());
        }
        if (catalogCategory && catalogCategory.value !== "") {
            params.set("category_id", catalogCategory.value);
        }
        fetch("/catalog/templates?" + params.toString(), { headers: { Accept: "application/json" } })
            .then(function (response) { return response.json(); })
            .then(renderCatalogTemplates)
            .catch(function () {
                catalogList.innerHTML = '<p class="table-empty mb-0">Vorlagen konnten nicht geladen werden.</p>';
            });
    }

    function openCatalogDialog(caseNumber, patientName) {
        catalogCase = caseNumber || "";
        if (catalogPatient) {
            catalogPatient.textContent = "Patientenmappe: " + (patientName || "") + " · Fallnummer " + catalogCase;
        }
        if (catalogSearch) {
            catalogSearch.value = "";
        }
        if (catalogCategory) {
            catalogCategory.value = "";
        }
        window.PatSignUI.openDialog("catalog-add-dialog");
        loadCatalogTemplates();
    }

    /* Neue Patientenmappe manuell anlegen, danach direkt Katalogdokumente hinzufügen */
    const folderCreateButton = document.getElementById("patient-folders-create");
    const folderCreateForm = document.getElementById("folder-create-form");
    const folderCreateSubmit = document.getElementById("folder-create-submit");

    if (folderCreateButton && folderCreateForm) {
        folderCreateButton.addEventListener("click", function () {
            window.PatSignUI.closeDialog("patient-folders-dialog");
            folderCreateForm.reset();
            window.PatSignUI.openDialog("folder-create-dialog");
        });
    }

    if (folderCreateForm) {
        folderCreateForm.addEventListener("submit", function (event) {
            event.preventDefault();
            const params = new URLSearchParams(new FormData(folderCreateForm));
            if (folderCreateSubmit) {
                folderCreateSubmit.disabled = true;
            }
            postForm("/dashboard/folder", params)
                .then(function (data) {
                    window.PatSignUI.toast(data.message || "Patientenmappe angelegt.", "success");
                    window.PatSignUI.closeDialog("folder-create-dialog");
                    const folder = data.folder || {};
                    const name = ((folder.last_name || "") + ", " + (folder.first_name || "")).replace(/^, |, $/g, "") || "Unbekannt";
                    openCatalogDialog(folder.case_number || "", name);
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                })
                .finally(function () {
                    if (folderCreateSubmit) {
                        folderCreateSubmit.disabled = false;
                    }
                });
        });
    }

    if (catalogSearch) {
        catalogSearch.addEventListener("input", function () {
            window.clearTimeout(catalogSearchTimer);
            catalogSearchTimer = window.setTimeout(loadCatalogTemplates, 250);
        });
    }
    if (catalogCategory) {
        catalogCategory.addEventListener("change", loadCatalogTemplates);
    }
    if (catalogList) {
        catalogList.addEventListener("change", updateCatalogSubmit);
    }

    if (catalogSubmit) {
        catalogSubmit.addEventListener("click", function () {
            const params = new URLSearchParams();
            params.set("case_number", catalogCase);
            catalogList.querySelectorAll("input[data-template-id]:checked").forEach(function (input) {
                params.append("template_ids[]", input.getAttribute("data-template-id"));
            });
            catalogSubmit.disabled = true;
            postForm("/catalog/add", params)
                .then(function (data) {
                    window.PatSignUI.toast(data.message || "Dokumente hinzugefügt.", "success");
                    if (catalogDialog) {
                        catalogDialog.close();
                    }
                    loadFolders();
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                })
                .finally(updateCatalogSubmit);
        });
    }

    function collectOrder(listElement) {
        return Array.prototype.map.call(
            listElement.querySelectorAll("[data-doc-id]"),
            function (item) { return item.getAttribute("data-doc-id"); }
        );
    }

    function saveOrder(listElement) {
        const params = new URLSearchParams();
        params.set("case_number", listElement.getAttribute("data-reorder-case") || "");
        collectOrder(listElement).forEach(function (id) {
            params.append("order[]", id);
        });
        postForm("/catalog/reorder", params)
            .then(function (data) {
                window.PatSignUI.toast(data.message || "Reihenfolge gespeichert.", "success");
            })
            .catch(function (error) {
                window.PatSignUI.toast(error.message, "error");
                loadFolders();
            });
    }

    if (foldersList) {
        foldersList.addEventListener("click", function (event) {
            const addTrigger = event.target.closest("[data-catalog-add]");
            if (addTrigger) {
                openCatalogDialog(
                    addTrigger.getAttribute("data-case-number") || "",
                    addTrigger.getAttribute("data-patient-name") || ""
                );
                return;
            }

            const removeTrigger = event.target.closest("[data-doc-remove]");
            if (removeTrigger) {
                const item = removeTrigger.closest("[data-doc-id]");
                const docName = removeTrigger.getAttribute("data-doc-name") || "Dokument";
                if (!item || !window.confirm("„" + docName + "“ wirklich aus der Mappe entfernen?")) {
                    return;
                }
                const params = new URLSearchParams();
                params.set("document_id", item.getAttribute("data-doc-id"));
                postForm("/catalog/remove", params)
                    .then(function (data) {
                        window.PatSignUI.toast(data.message || "Dokument entfernt.", "success");
                        loadFolders();
                    })
                    .catch(function (error) {
                        window.PatSignUI.toast(error.message, "error");
                    });
                return;
            }

            const moveTrigger = event.target.closest("[data-doc-move]");
            if (moveTrigger) {
                const item = moveTrigger.closest("[data-doc-id]");
                const listElement = item ? item.closest("[data-reorder-case]") : null;
                if (!item || !listElement) {
                    return;
                }
                if (moveTrigger.getAttribute("data-doc-move") === "up" && item.previousElementSibling) {
                    listElement.insertBefore(item, item.previousElementSibling);
                    saveOrder(listElement);
                } else if (moveTrigger.getAttribute("data-doc-move") === "down" && item.nextElementSibling) {
                    listElement.insertBefore(item.nextElementSibling, item);
                    saveOrder(listElement);
                }
            }
        });

        /* Drag & Drop innerhalb einer Mappe */
        let draggedItem = null;

        foldersList.addEventListener("dragstart", function (event) {
            const item = event.target.closest("[data-doc-id]");
            if (item) {
                draggedItem = item;
                event.dataTransfer.effectAllowed = "move";
            }
        });

        foldersList.addEventListener("dragover", function (event) {
            if (!draggedItem) {
                return;
            }
            const target = event.target.closest("[data-doc-id]");
            if (!target || target === draggedItem || target.parentElement !== draggedItem.parentElement) {
                return;
            }
            event.preventDefault();
            const rect = target.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            target.parentElement.insertBefore(draggedItem, after ? target.nextElementSibling : target);
        });

        foldersList.addEventListener("drop", function (event) {
            if (draggedItem) {
                event.preventDefault();
            }
        });

        foldersList.addEventListener("dragend", function () {
            if (draggedItem) {
                const listElement = draggedItem.closest("[data-reorder-case]");
                draggedItem = null;
                if (listElement) {
                    saveOrder(listElement);
                }
            }
        });
    }

    /* Kachel "KI wird ausgeführt": laufende Analysen mit Fortschritt und Notfall-Aktion */
    const analyzingDialog = document.getElementById("analyzing-dialog");
    const analyzingList = document.getElementById("analyzing-list");
    const analyzingTile = document.getElementById("analyzing-tile");
    const analyzingCount = document.getElementById("analyzing-count");
    let analyzingTimer = null;

    function formatElapsed(seconds) {
        const s = Math.max(0, seconds || 0);
        if (s < 60) {
            return s + " s";
        }
        return Math.floor(s / 60) + " min " + (s % 60) + " s";
    }

    function renderAnalyzing(data) {
        const docs = data.documents || [];

        if (analyzingCount) {
            analyzingCount.textContent = String(data.count || 0);
        }

        if (docs.length === 0) {
            analyzingList.innerHTML = '<p class="table-empty mb-0">Aktuell laufen keine KI-Analysen.</p>';
            return;
        }

        analyzingList.innerHTML = docs.map(function (doc) {
            const percent = doc.progress == null ? null : Math.round(doc.progress * 100);
            const progressHtml = percent == null
                ? '<div class="progress" aria-hidden="true"><div class="progress-bar analyzing-indeterminate" style="width: 40%"></div></div>'
                : '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + percent + '">' +
                  '<div class="progress-bar" style="width: ' + percent + '%"></div></div>';
            return '<div class="patient-row analyzing-row">' +
                '<div class="analyzing-info">' +
                '<div class="patient-name">' + escapeHtml(doc.file_name || "Dokument") + "</div>" +
                '<div class="patient-meta">Analyse läuft seit ' + formatElapsed(doc.elapsed_seconds) +
                (percent == null ? "" : " · ca. " + percent + " %") + "</div>" +
                progressHtml +
                "</div>" +
                '<div class="patient-actions">' +
                '<button type="button" class="btn btn-danger btn-sm" data-emergency="' + doc.id + '" ' +
                'title="Überspringt die Analyse und verschiebt Dokument direkt in das Clearing zur manuellen Zuordnung">' +
                "Notfall</button>" +
                "</div>" +
                "</div>";
        }).join("");
    }

    function loadAnalyzing() {
        if (!analyzingList) {
            return;
        }
        fetch("/dashboard/analyzing", { headers: { Accept: "application/json" } })
            .then(function (response) { return response.json(); })
            .then(renderAnalyzing)
            .catch(function () {
                analyzingList.innerHTML = '<p class="table-empty mb-0">Laufende Analysen konnten nicht geladen werden.</p>';
            });
    }

    function stopAnalyzingPolling() {
        if (analyzingTimer !== null) {
            window.clearInterval(analyzingTimer);
            analyzingTimer = null;
        }
    }

    if (analyzingTile && analyzingDialog && analyzingList) {
        analyzingTile.addEventListener("click", function () {
            loadAnalyzing();
            stopAnalyzingPolling();
            analyzingTimer = window.setInterval(loadAnalyzing, 3000);
        });
        analyzingDialog.addEventListener("close", stopAnalyzingPolling);
    }

    if (analyzingList) {
        analyzingList.addEventListener("click", function (event) {
            const trigger = event.target.closest("[data-emergency]");
            if (!trigger) {
                return;
            }
            trigger.disabled = true;
            const body = new URLSearchParams({
                _csrf: analyzingList.getAttribute("data-csrf") || "",
                document_id: trigger.getAttribute("data-emergency") || ""
            });
            fetch("/dashboard/emergency", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.data.error || "Aktion fehlgeschlagen");
                    }
                    window.PatSignUI.toast(result.data.message || "Dokument ins Clearing verschoben.", "success");
                    loadAnalyzing();
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                    trigger.disabled = false;
                });
        });
    }
})();
