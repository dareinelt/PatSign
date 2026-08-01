(function () {
    "use strict";

    const POLL_INTERVAL_MS = 10000;

    const center = document.getElementById("notification-center");
    const bell = document.getElementById("notification-bell");
    const badge = document.getElementById("notification-badge");
    const panel = document.getElementById("notification-panel");
    const list = document.getElementById("notification-list");
    const markAllButton = document.getElementById("notification-mark-all");
    if (!center || !bell || !badge || !panel || !list) {
        return;
    }

    const csrfToken = center.dataset.csrf || "";
    let lastUnread = 0;

    function formatTime(value) {
        if (!value) {
            return "";
        }
        const date = new Date(value.replace(" ", "T"));
        if (isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString("de-DE", {
            day: "2-digit",
            month: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
        });
    }

    function render(data) {
        const unread = data.unread || 0;
        badge.hidden = unread === 0;
        badge.textContent = unread > 99 ? "99+" : String(unread);
        lastUnread = unread;

        const items = Array.isArray(data.notifications) ? data.notifications : [];
        list.textContent = "";

        if (items.length === 0) {
            const empty = document.createElement("li");
            empty.className = "notification-empty";
            empty.textContent = "Keine Benachrichtigungen";
            list.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            const li = document.createElement("li");
            if (!item.read_at) {
                li.classList.add("is-unread");
            }

            const dot = document.createElement("span");
            dot.className = "notification-item-dot";
            if (item.type === "success") {
                dot.classList.add("is-success");
            } else if (item.type === "error") {
                dot.classList.add("is-error");
            } else {
                dot.classList.add("is-info");
            }
            dot.setAttribute("aria-hidden", "true");

            const body = document.createElement("div");
            body.className = "notification-item-body";

            const title = document.createElement("div");
            title.className = "notification-item-title";
            title.textContent = item.title || "";
            body.appendChild(title);

            if (item.message) {
                const message = document.createElement("div");
                message.className = "notification-item-message";
                message.textContent = item.message;
                body.appendChild(message);
            }

            const time = document.createElement("div");
            time.className = "notification-item-time";
            time.textContent = formatTime(item.created_at);
            body.appendChild(time);

            li.appendChild(dot);
            li.appendChild(body);
            list.appendChild(li);
        });
    }

    async function refresh() {
        try {
            const response = await fetch("/notifications", {
                headers: { Accept: "application/json" },
            });
            if (!response.ok) {
                return;
            }
            render(await response.json());
        } catch (error) {
            // Netzwerkfehler beim Polling stillschweigend ignorieren.
        }
    }

    async function markAllRead() {
        try {
            const body = new URLSearchParams();
            body.set("_csrf", csrfToken);
            const response = await fetch("/notifications/read", {
                method: "POST",
                headers: { Accept: "application/json" },
                body: body,
            });
            if (response.ok) {
                await refresh();
            }
        } catch (error) {
            // Ignorieren – nächster Poll aktualisiert den Zustand.
        }
    }

    function togglePanel(open) {
        const shouldOpen = typeof open === "boolean" ? open : panel.hidden;
        panel.hidden = !shouldOpen;
        bell.setAttribute("aria-expanded", shouldOpen ? "true" : "false");
        if (shouldOpen && lastUnread > 0) {
            markAllRead();
        }
    }

    bell.addEventListener("click", function (event) {
        event.stopPropagation();
        togglePanel();
    });

    if (markAllButton) {
        markAllButton.addEventListener("click", function (event) {
            event.stopPropagation();
            markAllRead();
        });
    }

    document.addEventListener("click", function (event) {
        if (!panel.hidden && !center.contains(event.target)) {
            togglePanel(false);
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !panel.hidden) {
            togglePanel(false);
        }
    });

    refresh();
    setInterval(refresh, POLL_INTERVAL_MS);
})();
