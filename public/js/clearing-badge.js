/* Zeigt die Anzahl offener Clearing-Vorgänge als Badge in der Hauptnavigation. */
(function () {
    "use strict";

    var badge = document.getElementById("clearing-nav-badge");
    if (!badge) {
        return;
    }

    function refresh() {
        fetch("/clearing/count", { headers: { Accept: "application/json" } })
            .then(function (response) { return response.ok ? response.json() : { open: 0 }; })
            .then(function (data) {
                var open = data.open || 0;
                badge.hidden = open === 0;
                badge.textContent = open > 99 ? "99+" : String(open);
            })
            .catch(function () { /* Badge ist unkritisch */ });
    }

    refresh();
    window.setInterval(refresh, 30000);
})();
