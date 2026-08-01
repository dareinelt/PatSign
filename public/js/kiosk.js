/* Kioskmodus: Geräteerkennung, Registrierung, Wartezustand, Signaturassistent */
(function () {
    "use strict";

    var STORAGE_ID = "patsign_device_uuid";
    var STORAGE_TOKEN = "patsign_device_token";
    var SOFTWARE_VERSION = "kiosk-1.0";

    function creds() {
        return {
            id: window.localStorage.getItem(STORAGE_ID) || "",
            token: window.localStorage.getItem(STORAGE_TOKEN) || ""
        };
    }

    function deviceHeaders(extra) {
        var c = creds();
        var headers = { Accept: "application/json" };
        if (c.id && c.token) {
            headers["X-Device-Id"] = c.id;
            headers["X-Device-Token"] = c.token;
        }
        for (var key in (extra || {})) {
            headers[key] = extra[key];
        }
        return headers;
    }

    /* Geräteerkennung: nicht nur User-Agent, sondern auch Touch und Bildschirmgröße */
    function isSupportedTablet() {
        var ua = window.navigator.userAgent || "";
        var touchPoints = window.navigator.maxTouchPoints || 0;
        var minDim = Math.min(window.screen.width, window.screen.height);
        var isIpadUa = /iPad/i.test(ua) || (/Macintosh/i.test(ua) && touchPoints > 1);
        var isAndroidTablet = /Android/i.test(ua) && !/Mobile/i.test(ua);
        var genericTablet = touchPoints > 1 && minDim >= 600;
        return isIpadUa || isAndroidTablet || genericTablet;
    }

    function platformInfo() {
        var ua = window.navigator.userAgent || "";
        var os = "Unbekannt";
        if (/iPad|iPhone|iPod/i.test(ua) || (/Macintosh/i.test(ua) && (window.navigator.maxTouchPoints || 0) > 1)) {
            os = "iPadOS/iOS";
        } else if (/Android/i.test(ua)) {
            os = "Android";
        } else if (/Windows/i.test(ua)) {
            os = "Windows";
        } else if (/Macintosh/i.test(ua)) {
            os = "macOS";
        } else if (/Linux/i.test(ua)) {
            os = "Linux";
        }

        var browser = "Unbekannt";
        if (/CriOS|Chrome/i.test(ua) && !/Edg/i.test(ua)) {
            browser = "Chrome";
        } else if (/FxiOS|Firefox/i.test(ua)) {
            browser = "Firefox";
        } else if (/Edg/i.test(ua)) {
            browser = "Edge";
        } else if (/Safari/i.test(ua)) {
            browser = "Safari";
        }
        return { os: os, browser: browser };
    }

    /* Gerätefingerprint aus stabilen Merkmalen (SHA-256) */
    function fingerprint() {
        var parts = [
            window.navigator.userAgent,
            window.navigator.language,
            String(window.navigator.maxTouchPoints || 0),
            window.screen.width + "x" + window.screen.height + "@" + (window.devicePixelRatio || 1),
            Intl.DateTimeFormat().resolvedOptions().timeZone || ""
        ].join("|");

        if (!(window.crypto && window.crypto.subtle)) {
            return Promise.resolve("");
        }
        return window.crypto.subtle.digest("SHA-256", new TextEncoder().encode(parts)).then(function (buffer) {
            return Array.from(new Uint8Array(buffer)).map(function (b) {
                return b.toString(16).padStart(2, "0");
            }).join("");
        }).catch(function () { return ""; });
    }

    /* ---------------------------------------------------------------- Registrierung */
    var registerShell = document.getElementById("kiosk-register");
    if (registerShell) {
        var csrfToken = registerShell.getAttribute("data-csrf") || "";

        var staffLink = document.getElementById("register-staff-link");
        if (staffLink) {
            staffLink.addEventListener("click", function () {
                window.sessionStorage.setItem("patsign_skip_kiosk", "1");
            });
        }

        // Falls Credentials vorhanden sind (Cookies verloren): Cookies wiederherstellen.
        var existing = creds();
        if (existing.id && existing.token) {
            fetch("/kiosk/reconnect", { method: "POST", headers: deviceHeaders() })
                .then(function (response) {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        window.localStorage.removeItem(STORAGE_ID);
                        window.localStorage.removeItem(STORAGE_TOKEN);
                    }
                })
                .catch(function () {});
        }

        if (!isSupportedTablet()) {
            var unsupported = document.getElementById("register-unsupported");
            if (unsupported) {
                unsupported.classList.remove("hidden");
            }
        }

        var form = document.getElementById("register-form");
        if (form) {
            form.addEventListener("submit", function (event) {
                event.preventDefault();
                var nameInput = document.getElementById("register-name");
                var errorBox = document.getElementById("register-error");
                var submit = document.getElementById("register-submit");
                var name = nameInput ? nameInput.value.trim() : "";

                function showError(message) {
                    if (errorBox) {
                        errorBox.textContent = message;
                        errorBox.classList.remove("hidden");
                    }
                }

                if (name === "") {
                    showError("Bitte einen Gerätenamen angeben.");
                    return;
                }

                submit.disabled = true;
                var info = platformInfo();
                fingerprint().then(function (fp) {
                    var body = new URLSearchParams();
                    body.set("_csrf", csrfToken);
                    body.set("name", name);
                    body.set("device_type", "tablet");
                    body.set("browser", info.browser);
                    body.set("os", info.os);
                    body.set("fingerprint", fp);
                    body.set("software_version", SOFTWARE_VERSION);

                    return fetch("/kiosk/register", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
                        body: body.toString()
                    });
                }).then(function (response) {
                    // Nicht-JSON-Antworten (z. B. Fehlerseiten) abfangen, damit
                    // eine verständliche Meldung statt eines Parserfehlers erscheint.
                    return response.json().catch(function () {
                        return { error: "Serverfehler (HTTP " + response.status + "). Bitte erneut versuchen." };
                    }).then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                }).then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.data.error || "Registrierung fehlgeschlagen.");
                    }
                    window.localStorage.setItem(STORAGE_ID, result.data.device_uuid);
                    window.localStorage.setItem(STORAGE_TOKEN, result.data.device_token);
                    window.location.href = "/kiosk";
                }).catch(function (error) {
                    showError(error.message);
                }).finally(function () {
                    submit.disabled = false;
                });
            });
        }
        return;
    }

    /* ---------------------------------------------------------------- Kioskoberfläche */
    var kioskShell = document.getElementById("kiosk");
    if (!kioskShell) {
        return;
    }

    var states = {
        waiting: document.getElementById("kiosk-waiting"),
        blocked: document.getElementById("kiosk-blocked"),
        wizard: document.getElementById("kiosk-wizard"),
        done: document.getElementById("kiosk-done")
    };
    var sessionToken = "";
    var assignment = null;
    var polling = false;
    var documentIndex = 0;
    var hasSignature = false;
    var signatureCtx = null;

    function escapeHtml(value) {
        var div = document.createElement("div");
        div.textContent = String(value == null ? "" : value);
        return div.innerHTML;
    }

    function showState(name) {
        Object.keys(states).forEach(function (key) {
            if (states[key]) {
                states[key].classList.toggle("is-active", key === name);
            }
        });
    }

    /* Wartezustand: Long Polling bis eine Patientenmappe zugewiesen wird */
    function pollLoop() {
        if (polling) {
            return;
        }
        polling = true;

        function next() {
            fetch("/kiosk/poll", { headers: deviceHeaders() })
                .then(function (response) {
                    if (response.status === 401) {
                        window.localStorage.removeItem(STORAGE_ID);
                        window.localStorage.removeItem(STORAGE_TOKEN);
                        window.location.reload();
                        return null;
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data) {
                        return;
                    }
                    if (data.status === "assigned") {
                        polling = false;
                        startWizard(data);
                        return;
                    }
                    if (data.status === "locked" || data.status === "retired") {
                        polling = false;
                        var text = document.getElementById("kiosk-blocked-text");
                        if (text) {
                            text.textContent = data.status === "locked"
                                ? "Dieses Gerät wurde gesperrt. Bitte wenden Sie sich an das Personal."
                                : "Dieses Gerät ist außer Betrieb.";
                        }
                        showState("blocked");
                        window.setTimeout(function () { window.location.reload(); }, 60000);
                        return;
                    }
                    window.setTimeout(next, 1000);
                })
                .catch(function () {
                    window.setTimeout(next, 5000);
                });
        }

        next();
    }

    /* Heartbeat: Lebenszeichen alle 30 Sekunden */
    window.setInterval(function () {
        var body = new URLSearchParams();
        body.set("software_version", SOFTWARE_VERSION);
        fetch("/kiosk/heartbeat", {
            method: "POST",
            headers: deviceHeaders({ "Content-Type": "application/x-www-form-urlencoded" }),
            body: body.toString()
        }).catch(function () {});
    }, 30000);

    /* ---------------------------------------------------------------- Wizard */
    var steps = Array.from(kioskShell.querySelectorAll(".wizard-step"));
    var progressBar = document.getElementById("wizard-progress-bar");
    var progressLabel = document.getElementById("wizard-progress-label");
    var currentStep = 0;

    function showStep(index) {
        currentStep = Math.max(0, Math.min(index, steps.length - 1));
        steps.forEach(function (step, i) {
            step.classList.toggle("is-active", i === currentStep);
        });
        var percent = Math.round(((currentStep + 1) / steps.length) * 100);
        if (progressBar) {
            progressBar.style.width = percent + "%";
            progressBar.parentElement.setAttribute("aria-valuenow", String(percent));
        }
        if (progressLabel) {
            progressLabel.textContent = "Schritt " + (currentStep + 1) + " von " + steps.length;
        }
        var live = document.getElementById("wizard-live");
        var heading = steps[currentStep].querySelector("h1, h2");
        if (live && heading) {
            live.textContent = heading.textContent;
        }
        window.scrollTo({ top: 0, behavior: "smooth" });
        if (canvas && steps[currentStep].contains(canvas)) {
            resizeCanvasSoon();
        }
    }

    function startWizard(state) {
        assignment = state.assignment;
        sessionToken = state.session_token || "";
        documentIndex = 0;

        var welcome = document.getElementById("kiosk-welcome");
        if (welcome) {
            welcome.textContent = "Herzlich willkommen" + (assignment.patient_name ? ", " + assignment.patient_name : "");
        }
        var label = document.getElementById("kiosk-patient-label");
        if (label) {
            label.textContent = (assignment.patient_name || "Patient") + " · Fall " + assignment.case_number;
        }
        var list = document.getElementById("kiosk-doc-list");
        if (list) {
            list.innerHTML = assignment.documents.map(function (doc, index) {
                return '<li><span class="doc-icon"><svg class="icon" aria-hidden="true"><use href="#icon-document"/></svg></span>' +
                    '<div><div class="doc-title">' + escapeHtml(doc.document_type || "Dokument") + "</div>" +
                    '<div class="text-muted text-sm">Dokument ' + (index + 1) + " von " + assignment.documents.length + "</div></div></li>";
            }).join("");
        }
        resetSignature();
        showStep(0);
        showState("wizard");
        resizeCanvasSoon();
    }

    /* Dokumentanzeige */
    var frame = document.getElementById("doc-frame");
    var docTitle = document.getElementById("doc-viewer-title");
    var docCounter = document.getElementById("doc-counter");
    var nextDocButton = document.getElementById("doc-next");
    var prevDocButton = document.getElementById("doc-prev");

    function loadDocument(index) {
        if (!assignment) {
            return;
        }
        documentIndex = Math.max(0, Math.min(index, assignment.documents.length - 1));
        var doc = assignment.documents[documentIndex];
        if (!doc) {
            return;
        }
        if (frame && window.PatSignPdfViewer) {
            window.PatSignPdfViewer.render(frame, "/kiosk/document?id=" + encodeURIComponent(doc.id));
        }
        if (docTitle) {
            docTitle.textContent = doc.document_type || "Dokument";
        }
        if (docCounter) {
            docCounter.textContent = "Dokument " + (documentIndex + 1) + " von " + assignment.documents.length;
        }
        if (nextDocButton) {
            nextDocButton.textContent = documentIndex < assignment.documents.length - 1 ? "Nächstes Dokument" : "Weiter";
        }
        if (prevDocButton) {
            prevDocButton.disabled = documentIndex === 0;
        }
    }

    document.addEventListener("click", function (event) {
        if (event.target.closest("[data-wizard-next]")) {
            showStep(currentStep + 1);
        }
        if (event.target.closest("[data-wizard-prev]")) {
            showStep(currentStep - 1);
        }
        if (event.target.closest("[data-open-documents]")) {
            showStep(currentStep + 1);
            loadDocument(0);
        }
    });

    if (nextDocButton) {
        nextDocButton.addEventListener("click", function () {
            if (assignment && documentIndex < assignment.documents.length - 1) {
                loadDocument(documentIndex + 1);
            } else {
                showStep(currentStep + 1);
            }
        });
    }
    if (prevDocButton) {
        prevDocButton.addEventListener("click", function () {
            loadDocument(documentIndex - 1);
        });
    }

    /* E-Mail-Zustimmung */
    var emailConsent = document.getElementById("email-consent");
    var emailField = document.getElementById("email-field");
    if (emailConsent && emailField) {
        emailConsent.addEventListener("change", function () {
            emailField.classList.toggle("hidden", !emailConsent.checked);
        });
    }

    /* Unterschriftsfeld (Finger, Maus, Apple Pencil) */
    var canvas = document.getElementById("signature-pad");
    var padWrapper = canvas ? canvas.closest(".signature-pad-wrapper") : null;

    function resizeCanvasSoon() {
        window.setTimeout(resizeCanvas, 50);
    }

    function resizeCanvas() {
        if (!canvas) {
            return;
        }
        var ratio = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        if (rect.width === 0) {
            return;
        }
        var image = hasSignature ? canvas.toDataURL() : null;
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        signatureCtx = canvas.getContext("2d");
        signatureCtx.scale(ratio, ratio);
        signatureCtx.lineWidth = 2.5;
        signatureCtx.lineCap = "round";
        signatureCtx.lineJoin = "round";
        signatureCtx.strokeStyle = "#1a2733";
        if (image) {
            var img = new Image();
            img.onload = function () {
                signatureCtx.drawImage(img, 0, 0, rect.width, rect.height);
            };
            img.src = image;
        }
    }

    function resetSignature() {
        hasSignature = false;
        if (padWrapper) {
            padWrapper.classList.remove("has-signature");
        }
        if (canvas && signatureCtx) {
            signatureCtx.clearRect(0, 0, canvas.width, canvas.height);
        }
        var readConfirmed = document.getElementById("read-confirmed");
        if (readConfirmed) {
            readConfirmed.checked = false;
        }
        if (emailConsent) {
            emailConsent.checked = false;
        }
        if (emailField) {
            emailField.classList.add("hidden");
        }
        var emailInput = document.getElementById("patient-email");
        if (emailInput) {
            emailInput.value = "";
        }
    }

    if (canvas) {
        var drawing = false;
        window.addEventListener("resize", resizeCanvas);

        function pointerPosition(event) {
            var rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }

        canvas.addEventListener("pointerdown", function (event) {
            event.preventDefault();
            if (!signatureCtx) {
                resizeCanvas();
                if (!signatureCtx) {
                    return;
                }
            }
            drawing = true;
            canvas.setPointerCapture(event.pointerId);
            var pos = pointerPosition(event);
            signatureCtx.beginPath();
            signatureCtx.moveTo(pos.x, pos.y);
        });
        canvas.addEventListener("pointermove", function (event) {
            if (!drawing) {
                return;
            }
            var pos = pointerPosition(event);
            signatureCtx.lineTo(pos.x, pos.y);
            signatureCtx.stroke();
            if (!hasSignature) {
                hasSignature = true;
                if (padWrapper) {
                    padWrapper.classList.add("has-signature");
                }
            }
        });
        ["pointerup", "pointercancel"].forEach(function (type) {
            canvas.addEventListener(type, function () {
                drawing = false;
            });
        });

        var clearButton = document.getElementById("signature-clear");
        if (clearButton) {
            clearButton.addEventListener("click", function () {
                if (signatureCtx) {
                    signatureCtx.clearRect(0, 0, canvas.width, canvas.height);
                }
                hasSignature = false;
                if (padWrapper) {
                    padWrapper.classList.remove("has-signature");
                }
            });
        }
    }

    /* Signatur absenden → automatische Freigabe des Geräts */
    var submitButton = document.getElementById("signature-submit");
    if (submitButton) {
        submitButton.addEventListener("click", function () {
            var readConfirmed = document.getElementById("read-confirmed");
            var errorBox = document.getElementById("signature-error");

            function showError(message) {
                if (errorBox) {
                    errorBox.textContent = message;
                    errorBox.classList.remove("hidden");
                }
            }

            if (!readConfirmed || !readConfirmed.checked) {
                showError("Bitte bestätigen Sie, dass Sie alle Dokumente gelesen haben.");
                return;
            }
            if (!hasSignature) {
                showError("Bitte unterschreiben Sie im Unterschriftsfeld.");
                return;
            }

            var consent = emailConsent && emailConsent.checked;
            var emailInput = document.getElementById("patient-email");
            var email = emailInput ? emailInput.value.trim() : "";
            if (consent && (email === "" || email.indexOf("@") < 1)) {
                showError("Bitte geben Sie eine gültige E-Mail-Adresse ein.");
                return;
            }

            var body = new URLSearchParams();
            body.set("read_confirmed", "1");
            body.set("email_consent", consent ? "1" : "0");
            body.set("email", email);
            body.set("signature_data", canvas ? canvas.toDataURL("image/png") : "");

            submitButton.disabled = true;
            submitButton.textContent = "Wird gespeichert …";

            fetch("/kiosk/sign", {
                method: "POST",
                headers: deviceHeaders({
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-Session-Token": sessionToken
                }),
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.data.error || "Signatur fehlgeschlagen");
                    }
                    // Rotiertes Gerätetoken übernehmen (Replay-Schutz).
                    if (result.data.device_token) {
                        window.localStorage.setItem(STORAGE_TOKEN, result.data.device_token);
                    }
                    var emailNote = document.getElementById("finish-email-note");
                    if (emailNote) {
                        emailNote.classList.toggle("hidden", !result.data.email_sent);
                    }
                    assignment = null;
                    sessionToken = "";
                    showState("done");
                    // Nach kurzer Dankesanzeige automatisch in den Wartezustand zurückkehren.
                    window.setTimeout(function () {
                        showState("waiting");
                        pollLoop();
                    }, 8000);
                })
                .catch(function (error) {
                    showError(error.message);
                })
                .finally(function () {
                    submitButton.disabled = false;
                    submitButton.textContent = "Unterschrift bestätigen";
                });
        });
    }

    /* Initialzustand */
    var initialStatus = kioskShell.getAttribute("data-device-status") || "active";
    if (initialStatus !== "active") {
        var blockedText = document.getElementById("kiosk-blocked-text");
        if (blockedText && initialStatus === "retired") {
            blockedText.textContent = "Dieses Gerät ist außer Betrieb.";
        }
        showState("blocked");
    } else {
        showState("waiting");
        pollLoop();
    }
})();
