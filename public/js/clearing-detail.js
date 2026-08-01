/* Clearing-Detailansicht: PDF-Vorschau mit Zoom, Werte speichern,
   Live-Patientensuche, neue Mappe, Neuanalyse, Archivieren, Abschluss. */
(function () {
    "use strict";

    var detail = document.getElementById("clearing-detail");
    if (!detail) {
        return;
    }

    var caseId = detail.dataset.caseId;
    var csrfToken = detail.dataset.csrf || "";

    function post(url, data) {
        var body = new URLSearchParams(data);
        body.set("_csrf", csrfToken);
        return fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    throw new Error(json.error || "Aktion fehlgeschlagen.");
                }
                return json;
            });
        });
    }

    function reloadSoon() {
        window.setTimeout(function () { window.location.reload(); }, 900);
    }

    // --- PDF-Vorschau mit Zoom -----------------------------------------
    var viewer = document.getElementById("clearing-pdf-viewer");
    var zoomLevel = 1;
    var zoomLabel = document.getElementById("pdf-zoom-level");

    function renderPdf() {
        if (viewer && window.PatSignPdfViewer) {
            window.PatSignPdfViewer.render(viewer, viewer.dataset.src);
        }
    }

    function applyZoom() {
        if (!viewer) { return; }
        viewer.style.setProperty("--pdf-zoom", String(zoomLevel));
        viewer.querySelectorAll(".pdf-page").forEach(function (page) {
            page.style.transformOrigin = "top left";
            page.style.transform = "scale(" + zoomLevel + ")";
        });
        if (zoomLabel) {
            zoomLabel.textContent = Math.round(zoomLevel * 100) + " %";
        }
    }

    var zoomIn = document.getElementById("pdf-zoom-in");
    var zoomOut = document.getElementById("pdf-zoom-out");
    if (zoomIn) {
        zoomIn.addEventListener("click", function () {
            zoomLevel = Math.min(3, zoomLevel + 0.25);
            applyZoom();
        });
    }
    if (zoomOut) {
        zoomOut.addEventListener("click", function () {
            zoomLevel = Math.max(0.5, zoomLevel - 0.25);
            applyZoom();
        });
    }

    renderPdf();

    // --- Werte speichern -------------------------------------------------
    var updateForm = document.getElementById("clearing-update-form");
    if (updateForm) {
        updateForm.addEventListener("submit", function (event) {
            event.preventDefault();
            var formData = new FormData(updateForm);
            var payload = { id: caseId };
            ["document_type", "case_number", "first_name", "last_name", "birth_date"].forEach(function (field) {
                payload[field] = formData.get(field) || "";
            });
            post("/clearing/update", payload)
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                    var badge = document.getElementById("clearing-status-badge");
                    if (badge) {
                        badge.textContent = "In Bearbeitung";
                        badge.className = "badge badge-info";
                    }
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    }

    // --- Live-Patientensuche ---------------------------------------------
    var searchInput = document.getElementById("patient-search");
    var searchResults = document.getElementById("patient-search-results");
    var searchTimer = null;

    function renderResults(results) {
        if (!searchResults) { return; }
        searchResults.innerHTML = "";
        if (!results.length) {
            var empty = document.createElement("li");
            empty.className = "search-empty";
            empty.textContent = "Keine Treffer";
            searchResults.appendChild(empty);
        }
        results.forEach(function (patient) {
            var item = document.createElement("li");
            var button = document.createElement("button");
            button.type = "button";
            var name = [patient.last_name, patient.first_name].filter(Boolean).join(", ");
            var birth = patient.birth_date ? new Date(patient.birth_date).toLocaleDateString("de-DE") : "";
            button.textContent = name + " · Fall " + (patient.case_number || "–") +
                (birth ? " · geb. " + birth : "") +
                (Number(patient.is_temporary) === 1 ? " · Temporär" : "");
            button.addEventListener("click", function () {
                if (!patient.case_number) {
                    window.PatSignUI.toast("Treffer hat keine Fallnummer.", "error");
                    return;
                }
                if (!window.confirm("Dokument dem Patienten \"" + name + "\" (Fall " + patient.case_number + ") zuordnen?")) {
                    return;
                }
                post("/clearing/assign", { ids: caseId, case_number: patient.case_number })
                    .then(function (data) {
                        window.PatSignUI.toast(data.message, "success");
                        reloadSoon();
                    })
                    .catch(function (error) {
                        window.PatSignUI.toast(error.message, "error");
                    });
            });
            item.appendChild(button);
            searchResults.appendChild(item);
        });
        searchResults.hidden = false;
    }

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            window.clearTimeout(searchTimer);
            var term = searchInput.value.trim();
            if (term.length < 2) {
                if (searchResults) { searchResults.hidden = true; }
                return;
            }
            searchTimer = window.setTimeout(function () {
                fetch("/clearing/patients/search?q=" + encodeURIComponent(term), { headers: { Accept: "application/json" } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) { renderResults(data.results || []); })
                    .catch(function () { window.PatSignUI.toast("Suche fehlgeschlagen", "error"); });
            }, 250);
        });
        document.addEventListener("click", function (event) {
            if (searchResults && !searchResults.contains(event.target) && event.target !== searchInput) {
                searchResults.hidden = true;
            }
        });
    }

    // --- Neue Patientenmappe ----------------------------------------------
    var folderForm = document.getElementById("clearing-folder-form");
    if (folderForm) {
        folderForm.addEventListener("submit", function (event) {
            event.preventDefault();
            var formData = new FormData(folderForm);
            post("/clearing/folder", {
                ids: caseId,
                case_number: formData.get("case_number") || "",
                first_name: formData.get("first_name") || "",
                last_name: formData.get("last_name") || "",
                birth_date: formData.get("birth_date") || ""
            })
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                    reloadSoon();
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    }

    // --- Neuanalyse ---------------------------------------------------------
    document.querySelectorAll("[data-reanalyze]").forEach(function (button) {
        button.addEventListener("click", function () {
            button.disabled = true;
            post("/clearing/reanalyze", { id: caseId, mode: button.dataset.reanalyze })
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                })
                .catch(function (error) {
                    button.disabled = false;
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    });

    // --- Archivieren / Abschließen ------------------------------------------
    var archiveBtn = document.getElementById("clearing-archive-btn");
    if (archiveBtn) {
        archiveBtn.addEventListener("click", function () {
            if (!window.confirm("Dokument wirklich archivieren? Es verlässt damit den Clearing-Bereich.")) {
                return;
            }
            post("/clearing/archive", { ids: caseId })
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                    window.setTimeout(function () { window.location.href = "/clearing"; }, 900);
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    }

    var completeBtn = document.getElementById("clearing-complete-btn");
    if (completeBtn) {
        completeBtn.addEventListener("click", function () {
            post("/clearing/complete", { id: caseId })
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                    window.setTimeout(function () { window.location.href = "/clearing"; }, 900);
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    }

    // Tastaturbedienung: Strg/Cmd+S speichert die Werte.
    document.addEventListener("keydown", function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key === "s" && updateForm) {
            event.preventDefault();
            updateForm.requestSubmit();
        }
    });
})();
