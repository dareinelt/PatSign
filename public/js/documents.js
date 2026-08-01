(function () {
    "use strict";

    const AUTO_CONFIRM_SECONDS = 5;

    const form = document.getElementById("document-upload-form");
    const dialog = document.getElementById("upload-result-dialog");
    if (!form || !dialog || typeof dialog.showModal !== "function") {
        return;
    }

    const titleEl = document.getElementById("upload-result-title");
    const messageEl = document.getElementById("upload-result-message");
    const okButton = document.getElementById("upload-result-ok");
    const submitButton = form.querySelector('button[type="submit"]');

    let countdownTimer = null;

    function stopCountdown() {
        if (countdownTimer !== null) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
        okButton.textContent = "OK";
    }

    function showResult(title, message) {
        titleEl.textContent = title;
        messageEl.textContent = message;
        dialog.showModal();
        okButton.focus();

        let remaining = AUTO_CONFIRM_SECONDS;
        okButton.textContent = "OK (" + remaining + ")";
        countdownTimer = setInterval(function () {
            remaining -= 1;
            if (remaining <= 0) {
                dialog.close();
                return;
            }
            okButton.textContent = "OK (" + remaining + ")";
        }, 1000);
    }

    dialog.addEventListener("close", stopCountdown);
    okButton.addEventListener("click", function () {
        dialog.close();
    });

    form.addEventListener("submit", async function (event) {
        event.preventDefault();

        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const response = await fetch(form.action, {
                method: "POST",
                body: new FormData(form),
                headers: { Accept: "application/json" },
            });

            let data = {};
            try {
                data = await response.json();
            } catch (parseError) {
                data = {};
            }

            if (response.ok && !data.error) {
                showResult("Import erfolgreich", data.message || "Das Dokument wurde importiert.");
                form.reset();
            } else {
                showResult("Import fehlgeschlagen", data.error || data.message || "Der Import ist fehlgeschlagen (HTTP " + response.status + ").");
            }
        } catch (networkError) {
            showResult("Import fehlgeschlagen", "Verbindungsfehler: Der Server ist nicht erreichbar.");
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    });
})();
