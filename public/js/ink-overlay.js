/* Freihand-Ebene über dem PDF (Apple Pencil / Stift).
   Legt pro Seite ein transparentes Canvas über den Seiten-Canvas.
   Stift (und Maus) zeichnen; Finger scrollen weiterhin normal.
   Striche werden in normalisierten Koordinaten gespeichert, damit sie
   Zoom/Resize überstehen und als PNG exportiert werden können. */
(function () {
    "use strict";

    var EXPORT_WIDTH = 1240; // ca. A4 bei 150 dpi
    var STROKE_COLOR = "#102452";
    var STROKE_WIDTH_REL = 0.0022; // Strichstärke relativ zur Seitenbreite

    function create() {
        // pages[pageNumber] = { canvas, ctx, aspect }
        var pages = {};
        // strokes: Reihenfolge der Eingabe über alle Seiten hinweg,
        // damit "Rückgängig" immer die letzte Eingabe entfernt.
        var strokes = [];
        var listeners = [];

        function pageStrokes(pageNumber) {
            return strokes.filter(function (s) { return s.page === pageNumber; });
        }

        function notify() {
            listeners.forEach(function (fn) {
                try { fn(strokes.length); } catch (e) { /* ignorieren */ }
            });
        }

        function drawStroke(ctx, width, height, stroke) {
            if (stroke.points.length === 0) {
                return;
            }
            ctx.strokeStyle = STROKE_COLOR;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            ctx.lineWidth = Math.max(1, STROKE_WIDTH_REL * width);
            ctx.beginPath();
            ctx.moveTo(stroke.points[0].x * width, stroke.points[0].y * height);
            for (var i = 1; i < stroke.points.length; i += 1) {
                ctx.lineTo(stroke.points[i].x * width, stroke.points[i].y * height);
            }
            if (stroke.points.length === 1) {
                ctx.lineTo(stroke.points[0].x * width + 0.1, stroke.points[0].y * height);
            }
            ctx.stroke();
        }

        function redrawPage(pageNumber) {
            var page = pages[pageNumber];
            if (!page || !page.ctx) {
                return;
            }
            page.ctx.clearRect(0, 0, page.canvas.width, page.canvas.height);
            pageStrokes(pageNumber).forEach(function (stroke) {
                drawStroke(page.ctx, page.canvas.width, page.canvas.height, stroke);
            });
        }

        function attachPage(pageNumber, wrapper) {
            var pageCanvas = wrapper.querySelector("canvas.pdf-page");
            var rect = pageCanvas ? pageCanvas.getBoundingClientRect() : wrapper.getBoundingClientRect();
            var ratio = Math.min(window.devicePixelRatio || 1, 3);

            var canvas = document.createElement("canvas");
            canvas.className = "ink-layer";
            canvas.width = Math.max(1, Math.round(rect.width * ratio));
            canvas.height = Math.max(1, Math.round(rect.height * ratio));
            canvas.setAttribute("aria-label", "Zeichenfläche für Stifteingaben");
            wrapper.appendChild(canvas);

            pages[pageNumber] = {
                canvas: canvas,
                ctx: canvas.getContext("2d"),
                aspect: rect.width > 0 ? rect.height / rect.width : Math.SQRT2
            };
            redrawPage(pageNumber);

            var current = null;
            var touchScroll = null;

            function scrollParent() {
                var node = canvas.parentElement;
                while (node && node !== document.body) {
                    if (node.scrollHeight > node.clientHeight) {
                        return node;
                    }
                    node = node.parentElement;
                }
                return null;
            }

            function position(event) {
                var box = canvas.getBoundingClientRect();
                if (box.width === 0 || box.height === 0) {
                    return null;
                }
                return {
                    x: Math.min(1, Math.max(0, (event.clientX - box.left) / box.width)),
                    y: Math.min(1, Math.max(0, (event.clientY - box.top) / box.height))
                };
            }

            canvas.addEventListener("pointerdown", function (event) {
                // Finger scrollen weiterhin (manuelles Durchreichen an den
                // Scrollcontainer); nur Stift und Maus zeichnen.
                if (event.pointerType === "touch") {
                    touchScroll = { y: event.clientY, target: scrollParent() };
                    return;
                }
                event.preventDefault();
                var pos = position(event);
                if (!pos) {
                    return;
                }
                canvas.setPointerCapture(event.pointerId);
                current = { page: pageNumber, points: [pos] };
                strokes.push(current);
                redrawPage(pageNumber);
                notify();
            });

            canvas.addEventListener("pointermove", function (event) {
                if (touchScroll && event.pointerType === "touch") {
                    if (touchScroll.target) {
                        touchScroll.target.scrollTop += touchScroll.y - event.clientY;
                    }
                    touchScroll.y = event.clientY;
                    return;
                }
                if (!current) {
                    return;
                }
                event.preventDefault();
                var pos = position(event);
                if (pos) {
                    current.points.push(pos);
                    redrawPage(pageNumber);
                }
            });

            ["pointerup", "pointercancel"].forEach(function (type) {
                canvas.addEventListener(type, function () {
                    current = null;
                    touchScroll = null;
                });
            });
        }

        function undo() {
            var stroke = strokes.pop();
            if (!stroke) {
                return;
            }
            redrawPage(stroke.page);
            notify();
        }

        /* PNG-Export je Seite (nur Seiten mit Strichen): { seite: dataUrl } */
        function exportPages() {
            var result = {};
            var byPage = {};
            strokes.forEach(function (stroke) {
                (byPage[stroke.page] = byPage[stroke.page] || []).push(stroke);
            });
            Object.keys(byPage).forEach(function (pageNumber) {
                var page = pages[pageNumber];
                var aspect = page ? page.aspect : Math.SQRT2;
                var canvas = document.createElement("canvas");
                canvas.width = EXPORT_WIDTH;
                canvas.height = Math.max(1, Math.round(EXPORT_WIDTH * aspect));
                var ctx = canvas.getContext("2d");
                byPage[pageNumber].forEach(function (stroke) {
                    drawStroke(ctx, canvas.width, canvas.height, stroke);
                });
                result[pageNumber] = canvas.toDataURL("image/png");
            });
            return result;
        }

        function clear() {
            strokes = [];
            Object.keys(pages).forEach(function (pageNumber) {
                redrawPage(pageNumber);
            });
            notify();
        }

        /* Seiten-Canvases verwerfen (z. B. vor dem Neurendern des PDFs);
           die Striche bleiben erhalten und werden beim nächsten attachPage
           wieder gezeichnet. */
        function detach() {
            Object.keys(pages).forEach(function (pageNumber) {
                var page = pages[pageNumber];
                if (page.canvas && page.canvas.parentElement) {
                    page.canvas.parentElement.removeChild(page.canvas);
                }
            });
            pages = {};
        }

        return {
            attachPage: attachPage,
            undo: undo,
            exportPages: exportPages,
            clear: clear,
            detach: detach,
            hasStrokes: function () { return strokes.length > 0; },
            onChange: function (fn) { listeners.push(fn); }
        };
    }

    window.PatSignInkOverlay = { create: create };
})();
