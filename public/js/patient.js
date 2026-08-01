/* Patientenmodus: Wizard, Dokumentanzeige, Unterschrift */
(function () {
    "use strict";

    const shell = document.getElementById("patient-wizard");
    if (!shell) {
        return;
    }

    const steps = Array.from(shell.querySelectorAll(".wizard-step"));
    const progressBar = document.getElementById("wizard-progress-bar");
    const progressLabel = document.getElementById("wizard-progress-label");
    const documents = JSON.parse(shell.getAttribute("data-documents") || "[]");
    const csrf = shell.getAttribute("data-csrf") || "";

    let currentStep = 0;
    let documentIndex = 0;
    const viewedDocuments = new Set();

    function announce(text) {
        const live = document.getElementById("wizard-live");
        if (live) {
            live.textContent = text;
        }
    }

    function showStep(index) {
        currentStep = Math.max(0, Math.min(index, steps.length - 1));
        steps.forEach(function (step, i) {
            step.classList.toggle("is-active", i === currentStep);
        });

        const percent = Math.round(((currentStep + 1) / steps.length) * 100);
        if (progressBar) {
            progressBar.style.width = percent + "%";
            progressBar.parentElement.setAttribute("aria-valuenow", String(percent));
        }
        if (progressLabel) {
            progressLabel.textContent = "Schritt " + (currentStep + 1) + " von " + steps.length;
        }

        const heading = steps[currentStep].querySelector("h1, h2");
        if (heading) {
            announce(heading.textContent);
            heading.setAttribute("tabindex", "-1");
            heading.focus({ preventScroll: false });
        }

        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    /* Dokumentanzeige */
    const frame = document.getElementById("doc-frame");
    const docTitle = document.getElementById("doc-viewer-title");
    const docCounter = document.getElementById("doc-counter");
    const nextDocButton = document.getElementById("doc-next");

    function loadDocument(index) {
        documentIndex = Math.max(0, Math.min(index, documents.length - 1));
        const doc = documents[documentIndex];
        if (!doc) {
            return;
        }

        if (frame && window.PatSignPdfViewer) {
            window.PatSignPdfViewer.render(frame, "/patient/document?id=" + encodeURIComponent(doc.id));
        }
        if (docTitle) {
            docTitle.textContent = doc.document_type || "Dokument";
        }
        if (docCounter) {
            docCounter.textContent = "Dokument " + (documentIndex + 1) + " von " + documents.length;
        }
        viewedDocuments.add(doc.id);

        if (nextDocButton) {
            nextDocButton.textContent = documentIndex < documents.length - 1 ? "Nächstes Dokument" : "Weiter";
        }

        const prevButton = document.getElementById("doc-prev");
        if (prevButton) {
            prevButton.disabled = documentIndex === 0;
        }
    }

    document.addEventListener("click", function (event) {
        const next = event.target.closest("[data-wizard-next]");
        if (next) {
            showStep(currentStep + 1);
        }

        const prev = event.target.closest("[data-wizard-prev]");
        if (prev) {
            showStep(currentStep - 1);
        }

        const open = event.target.closest("[data-open-documents]");
        if (open) {
            showStep(currentStep + 1);
            loadDocument(0);
        }
    });

    if (nextDocButton) {
        nextDocButton.addEventListener("click", function () {
            if (documentIndex < documents.length - 1) {
                loadDocument(documentIndex + 1);
            } else {
                showStep(currentStep + 1);
            }
        });
    }

    const prevDocButton = document.getElementById("doc-prev");
    if (prevDocButton) {
        prevDocButton.addEventListener("click", function () {
            loadDocument(documentIndex - 1);
        });
    }

    /* E-Mail-Zustimmung */
    const emailConsent = document.getElementById("email-consent");
    const emailField = document.getElementById("email-field");
    if (emailConsent && emailField) {
        emailConsent.addEventListener("change", function () {
            emailField.classList.toggle("hidden", !emailConsent.checked);
            const input = emailField.querySelector("input");
            if (input) {
                input.required = emailConsent.checked;
                if (emailConsent.checked) {
                    input.focus();
                }
            }
        });
    }

    /* Unterschriftsfeld mit Pointer-Events (Finger, Maus, Apple Pencil) */
    const canvas = document.getElementById("signature-pad");
    const padWrapper = canvas ? canvas.closest(".signature-pad-wrapper") : null;
    let hasSignature = false;

    if (canvas) {
        const ctx = canvas.getContext("2d");
        let drawing = false;

        function resizeCanvas() {
            const ratio = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            const image = hasSignature ? canvas.toDataURL() : null;
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2.5;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            ctx.strokeStyle = "#1a2733";
            if (image) {
                const img = new Image();
                img.onload = function () {
                    ctx.drawImage(img, 0, 0, rect.width, rect.height);
                };
                img.src = image;
            }
        }

        resizeCanvas();
        window.addEventListener("resize", resizeCanvas);

        function pointerPosition(event) {
            const rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }

        canvas.addEventListener("pointerdown", function (event) {
            event.preventDefault();
            drawing = true;
            canvas.setPointerCapture(event.pointerId);
            const pos = pointerPosition(event);
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
        });

        canvas.addEventListener("pointermove", function (event) {
            if (!drawing) {
                return;
            }
            const pos = pointerPosition(event);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
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

        const clearButton = document.getElementById("signature-clear");
        if (clearButton) {
            clearButton.addEventListener("click", function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasSignature = false;
                if (padWrapper) {
                    padWrapper.classList.remove("has-signature");
                }
            });
        }
    }

    /* Signatur absenden */
    const submitButton = document.getElementById("signature-submit");
    if (submitButton) {
        submitButton.addEventListener("click", function () {
            const readConfirmed = document.getElementById("read-confirmed");
            const errorBox = document.getElementById("signature-error");

            function showError(message) {
                if (errorBox) {
                    errorBox.textContent = message;
                    errorBox.classList.remove("hidden");
                }
                window.PatSignUI.toast(message, "error");
            }

            if (!readConfirmed || !readConfirmed.checked) {
                showError("Bitte bestätigen Sie, dass Sie alle Dokumente gelesen haben.");
                return;
            }
            if (!hasSignature) {
                showError("Bitte unterschreiben Sie im Unterschriftsfeld.");
                return;
            }

            const consent = emailConsent && emailConsent.checked;
            const emailInput = document.getElementById("patient-email");
            const email = emailInput ? emailInput.value.trim() : "";
            if (consent && (email === "" || email.indexOf("@") < 1)) {
                showError("Bitte geben Sie eine gültige E-Mail-Adresse ein.");
                return;
            }

            const body = new URLSearchParams();
            body.set("_csrf", csrf);
            body.set("read_confirmed", "1");
            body.set("email_consent", consent ? "1" : "0");
            body.set("email", email);
            body.set("signature_data", canvas ? canvas.toDataURL("image/png") : "");

            submitButton.disabled = true;
            submitButton.textContent = "Wird gespeichert …";

            fetch("/patient/sign", {
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
                        throw new Error(result.data.error || "Signatur fehlgeschlagen");
                    }
                    const emailNote = document.getElementById("finish-email-note");
                    if (emailNote) {
                        emailNote.classList.toggle("hidden", !result.data.email_sent);
                    }
                    fillSummary(consent, email);
                    showStep(currentStep + 1);
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

    function fillSummary(consent, email) {
        const summaryDocs = document.getElementById("summary-doc-count");
        if (summaryDocs) {
            summaryDocs.textContent = documents.length + " Dokument(e) unterschrieben";
        }
        const summaryEmail = document.getElementById("summary-email");
        if (summaryEmail) {
            summaryEmail.textContent = consent ? email : "Kein E-Mail-Versand gewünscht";
        }
    }

    showStep(0);
})();
