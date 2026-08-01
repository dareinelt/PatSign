/* Gemeinsamer PDF-Viewer auf Basis von PDF.js.
   Rendert alle Seiten eines PDFs in einen scrollbaren Container.
   Ersetzt die iframe-Anzeige, die auf iOS/iPadOS keine PDFs darstellt. */
(function () {
    "use strict";

    var pdfjsPromise = null;
    var renderToken = 0;

    function loadPdfJs() {
        if (!pdfjsPromise) {
            pdfjsPromise = import("/vendor/pdfjs/pdf.min.mjs").then(function (pdfjs) {
                pdfjs.GlobalWorkerOptions.workerSrc = "/vendor/pdfjs/pdf.worker.min.mjs";
                return pdfjs;
            });
        }
        return pdfjsPromise;
    }

    function showMessage(container, text, isError) {
        container.innerHTML = "";
        var note = document.createElement("p");
        note.className = "pdf-viewer-message" + (isError ? " is-error" : "");
        note.textContent = text;
        container.appendChild(note);
    }

    /**
     * Rendert das PDF unter `url` in den Container.
     * Ein späterer Aufruf bricht die Anzeige eines früheren Aufrufs ab.
     */
    function render(container, url) {
        if (!container) {
            return Promise.resolve();
        }

        var token = ++renderToken;
        showMessage(container, "Dokument wird geladen …", false);

        return loadPdfJs()
            .then(function (pdfjs) {
                return pdfjs.getDocument({ url: url }).promise;
            })
            .then(function (pdf) {
                if (token !== renderToken) {
                    return null;
                }
                container.innerHTML = "";
                container.scrollTop = 0;

                var pageWidth = Math.max(container.clientWidth - 2, 280);
                var pixelRatio = Math.min(window.devicePixelRatio || 1, 3);

                var chain = Promise.resolve();
                var renderPage = function (pageNumber) {
                    chain = chain.then(function () {
                        if (token !== renderToken) {
                            return null;
                        }
                        return pdf.getPage(pageNumber).then(function (page) {
                            if (token !== renderToken) {
                                return null;
                            }
                            var baseViewport = page.getViewport({ scale: 1 });
                            var scale = pageWidth / baseViewport.width;
                            var viewport = page.getViewport({ scale: scale * pixelRatio });

                            var canvas = document.createElement("canvas");
                            canvas.className = "pdf-page";
                            canvas.width = Math.floor(viewport.width);
                            canvas.height = Math.floor(viewport.height);
                            canvas.style.width = Math.floor(viewport.width / pixelRatio) + "px";
                            canvas.style.height = Math.floor(viewport.height / pixelRatio) + "px";
                            container.appendChild(canvas);

                            return page.render({
                                canvasContext: canvas.getContext("2d"),
                                viewport: viewport
                            }).promise;
                        });
                    });
                };

                for (var i = 1; i <= pdf.numPages; i += 1) {
                    renderPage(i);
                }
                return chain;
            })
            .catch(function (error) {
                if (token === renderToken) {
                    showMessage(container, "Das Dokument konnte nicht angezeigt werden. Bitte wenden Sie sich an das Personal.", true);
                }
                if (window.console && console.error) {
                    console.error("PDF-Anzeige fehlgeschlagen:", error);
                }
            });
    }

    window.PatSignPdfViewer = { render: render };
})();
