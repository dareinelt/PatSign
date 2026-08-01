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
})();
