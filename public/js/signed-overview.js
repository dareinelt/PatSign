/* Übersicht der heute unterschriebenen Dokumente:
   Rendert für jede Karte eine PDF-Vorschau über den gemeinsamen Viewer. */
(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        if (!window.PatSignPdfViewer) {
            return;
        }

        var previews = document.querySelectorAll("[data-pdf-preview]");
        // Sequenziell rendern: ein neuer Aufruf des Viewers bricht laufende
        // Renderings ab, daher warten wir jeweils auf den Abschluss.
        var chain = Promise.resolve();
        previews.forEach(function (container) {
            var id = container.getAttribute("data-document-id");
            if (!id) {
                return;
            }
            chain = chain.then(function () {
                return window.PatSignPdfViewer.render(container, "/dashboard/signed/document?id=" + encodeURIComponent(id));
            });
        });
    });
})();
