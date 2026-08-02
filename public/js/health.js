// Healthcheck-Seite: automatische Aktualisierung von Ampeln und 48h-Zeitstrahl
(function () {
    'use strict';

    const STATUS_LABELS = { ok: 'OK', warn: 'Warnung', error: 'Fehler', none: 'Keine Daten' };
    const OVERALL_LABELS = { ok: 'Alle Systeme betriebsbereit', warn: 'Eingeschränkter Betrieb', error: 'Störung' };

    function setStatusClass(el, prefix, status) {
        el.classList.remove(prefix + 'ok', prefix + 'warn', prefix + 'error', prefix + 'none');
        el.classList.add(prefix + status);
    }

    function renderChecks(checks) {
        checks.forEach((check) => {
            const item = document.querySelector('.health-check[data-key="' + check.key + '"]');
            if (!item) return;
            setStatusClass(item, 'is-', check.status);
            const dot = item.querySelector('.status-dot');
            if (dot) setStatusClass(dot, 'is-', check.status);
            const detail = item.querySelector('.health-check-detail');
            if (detail) detail.textContent = check.detail;
        });
    }

    function renderTimeline(timeline) {
        timeline.forEach((row) => {
            const rowEl = document.querySelector('.health-timeline-row[data-key="' + row.key + '"]');
            if (!rowEl) return;
            const slotEls = rowEl.querySelectorAll('.health-timeline-slots .health-slot');
            row.slots.forEach((slot, i) => {
                const el = slotEls[i];
                if (!el) return;
                setStatusClass(el, 'is-', slot.status);
                el.title = slot.hour + ': ' + (STATUS_LABELS[slot.status] || slot.status);
            });
        });
    }

    function renderOverall(status) {
        const overall = document.getElementById('health-overall');
        const label = document.getElementById('health-overall-label');
        if (overall) {
            setStatusClass(overall, 'is-', status);
            const dot = overall.querySelector('.status-dot');
            if (dot) setStatusClass(dot, 'is-', status);
        }
        if (label) label.textContent = OVERALL_LABELS[status] || status;
    }

    async function refresh() {
        try {
            const response = await fetch('/health/data', { headers: { Accept: 'application/json' } });
            const data = await response.json();
            renderOverall(data.status);
            renderChecks(data.checks || []);
            renderTimeline(data.timeline || []);
            const generated = document.getElementById('health-generated');
            if (generated && data.generated_at) {
                generated.textContent = new Date(data.generated_at).toLocaleString('de-DE');
            }
        } catch (e) {
            // Bei Netzwerkfehlern bleibt der letzte Stand sichtbar.
        }
    }

    setInterval(refresh, 60000);
})();
