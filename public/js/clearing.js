/* Clearing-Übersicht: Mehrfachauswahl und Sammelaktionen
   (Patient zuordnen, neue Mappe, archivieren). */
(function () {
    "use strict";

    var bulkActions = document.getElementById("clearing-bulk-actions");
    if (!bulkActions) {
        return;
    }

    var csrfToken = bulkActions.dataset.csrf || "";
    var selectAll = document.getElementById("clearing-select-all");
    var selectedCount = document.getElementById("clearing-selected-count");
    var selectedPatient = null;

    function selectedIds() {
        return Array.prototype.slice
            .call(document.querySelectorAll(".clearing-select:checked"))
            .map(function (box) { return box.value; });
    }

    function updateBulkBar() {
        var ids = selectedIds();
        bulkActions.hidden = ids.length === 0;
        if (selectedCount) {
            selectedCount.textContent = ids.length + " ausgewählt";
        }
    }

    document.addEventListener("change", function (event) {
        if (event.target.classList && event.target.classList.contains("clearing-select")) {
            updateBulkBar();
        }
    });

    if (selectAll) {
        selectAll.addEventListener("change", function () {
            document.querySelectorAll(".clearing-select").forEach(function (box) {
                box.checked = selectAll.checked;
            });
            updateBulkBar();
        });
    }

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

    // --- Bestehendem Patienten zuordnen -------------------------------
    var assignBtn = document.getElementById("bulk-assign-btn");
    var assignDialog = document.getElementById("bulk-assign-dialog");
    var searchInput = document.getElementById("bulk-patient-search");
    var searchResults = document.getElementById("bulk-patient-results");
    var assignSelected = document.getElementById("bulk-assign-selected");
    var assignConfirm = document.getElementById("bulk-assign-confirm");
    var searchTimer = null;

    if (assignBtn && assignDialog) {
        assignBtn.addEventListener("click", function () {
            selectedPatient = null;
            if (assignSelected) { assignSelected.textContent = "Kein Patient ausgewählt."; }
            if (assignConfirm) { assignConfirm.disabled = true; }
            if (searchInput) { searchInput.value = ""; }
            if (searchResults) { searchResults.hidden = true; }
            window.PatSignUI.openDialog("bulk-assign-dialog");
            if (searchInput) { searchInput.focus(); }
        });
    }

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
                selectedPatient = patient;
                if (assignSelected) {
                    assignSelected.textContent = "Ausgewählt: " + button.textContent;
                }
                if (assignConfirm) { assignConfirm.disabled = false; }
                searchResults.hidden = true;
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
    }

    if (assignConfirm) {
        assignConfirm.addEventListener("click", function () {
            if (!selectedPatient || !selectedPatient.case_number) {
                return;
            }
            assignConfirm.disabled = true;
            post("/clearing/assign", { ids: selectedIds().join(","), case_number: selectedPatient.case_number })
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                    window.PatSignUI.closeDialog("bulk-assign-dialog");
                    reloadSoon();
                })
                .catch(function (error) {
                    assignConfirm.disabled = false;
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    }

    // --- Neue Mappe ----------------------------------------------------
    var folderBtn = document.getElementById("bulk-folder-btn");
    var folderForm = document.getElementById("bulk-folder-form");

    if (folderBtn) {
        folderBtn.addEventListener("click", function () {
            window.PatSignUI.openDialog("bulk-folder-dialog");
        });
    }

    if (folderForm) {
        folderForm.addEventListener("submit", function (event) {
            event.preventDefault();
            var formData = new FormData(folderForm);
            post("/clearing/folder", {
                ids: selectedIds().join(","),
                case_number: formData.get("case_number") || "",
                first_name: formData.get("first_name") || "",
                last_name: formData.get("last_name") || "",
                birth_date: formData.get("birth_date") || ""
            })
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                    window.PatSignUI.closeDialog("bulk-folder-dialog");
                    reloadSoon();
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    }

    // --- Archivieren ----------------------------------------------------
    var archiveBtn = document.getElementById("bulk-archive-btn");
    if (archiveBtn) {
        archiveBtn.addEventListener("click", function () {
            if (!window.confirm("Ausgewählte Dokumente wirklich archivieren?")) {
                return;
            }
            post("/clearing/archive", { ids: selectedIds().join(",") })
                .then(function (data) {
                    window.PatSignUI.toast(data.message, "success");
                    reloadSoon();
                })
                .catch(function (error) {
                    window.PatSignUI.toast(error.message, "error");
                });
        });
    }
})();
