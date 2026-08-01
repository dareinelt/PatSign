/* PatSign UI-Basisfunktionen: Toasts, Dialoge, kleine Hilfen */
(function () {
    "use strict";

    function ensureToastRegion() {
        let region = document.querySelector(".toast-region");
        if (!region) {
            region = document.createElement("div");
            region.className = "toast-region";
            region.setAttribute("role", "status");
            region.setAttribute("aria-live", "polite");
            document.body.appendChild(region);
        }
        return region;
    }

    function showToast(message, type, duration) {
        const region = ensureToastRegion();
        const toast = document.createElement("div");
        toast.className = "toast" + (type ? " is-" + type : "");
        toast.textContent = message;
        region.appendChild(toast);
        window.setTimeout(function () {
            toast.remove();
        }, duration || 4000);
    }

    function openDialog(id) {
        const dialog = document.getElementById(id);
        if (dialog && typeof dialog.showModal === "function") {
            dialog.showModal();
        }
    }

    function closeDialog(id) {
        const dialog = document.getElementById(id);
        if (dialog) {
            dialog.close();
        }
    }

    // Deklaratives Öffnen/Schließen über data-Attribute
    document.addEventListener("click", function (event) {
        const opener = event.target.closest("[data-dialog-open]");
        if (opener) {
            openDialog(opener.getAttribute("data-dialog-open"));
        }
        const closer = event.target.closest("[data-dialog-close]");
        if (closer) {
            const dialog = closer.closest("dialog");
            if (dialog) {
                dialog.close();
            }
        }
    });

    // Bestätigungsabfrage für kritische Formulare
    document.addEventListener("submit", function (event) {
        const form = event.target;
        if (form.matches("[data-confirm]") && !window.confirm(form.getAttribute("data-confirm"))) {
            event.preventDefault();
        }
    });

    window.PatSignUI = {
        toast: showToast,
        openDialog: openDialog,
        closeDialog: closeDialog
    };
})();
