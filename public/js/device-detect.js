/* Automatische Tablet-Erkennung: leitet unterstützte Tablets in den Kioskmodus */
(function () {
    "use strict";

    // Personal kann die Umleitung bewusst überspringen (Link "Zur Personal-Anmeldung").
    if (window.sessionStorage.getItem("patsign_skip_kiosk") === "1") {
        return;
    }

    var registered = window.localStorage.getItem("patsign_device_uuid");
    var touchPoints = window.navigator.maxTouchPoints || 0;
    var ua = window.navigator.userAgent || "";
    var minDim = Math.min(window.screen.width, window.screen.height);
    var isIpad = /iPad/i.test(ua) || (/Macintosh/i.test(ua) && touchPoints > 1);
    var isAndroidTablet = /Android/i.test(ua) && !/Mobile/i.test(ua);
    var isTablet = isIpad || isAndroidTablet || (touchPoints > 1 && minDim >= 600);

    if (registered || isTablet) {
        window.location.replace("/kiosk");
    }
})();
