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
})();
