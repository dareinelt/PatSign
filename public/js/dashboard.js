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
                return '<li class="folder-document">' +
                    '<span class="folder-document-mark" aria-hidden="true">' + (isSigned ? "✓" : "○") + "</span>" +
                    '<span class="folder-document-type">' + escapeHtml(doc.document_type || "Dokument") + "</span>" +
                    '<span class="badge ' + badgeClass + '">' + escapeHtml(documentStatusLabels[doc.status] || doc.status) + "</span>" +
                    "</li>";
            }).join("");

            return '<div class="patient-row folder-row">' +
                "<div>" +
                '<div class="patient-name">' + escapeHtml(name) + "</div>" +
                '<div class="patient-meta">Fallnummer ' + escapeHtml(folder.case_number || "–") +
                " · " + (folder.document_count || 0) + " Dokument(e), davon " + openCount + " offen</div>" +
                '<ul class="folder-documents">' + documentsHtml + "</ul>" +
                "</div>" +
                '<div class="patient-actions">' +
                '<button type="button" class="btn btn-primary btn-sm" data-folder-start="' + escapeAttr(folder.case_number || "") + '">Patientenmodus</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" data-send-to-device data-case-number="' + escapeAttr(folder.case_number || "") + '" data-patient-name="' + escapeAttr(name) + '">An Gerät senden</button>' +
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
})();
