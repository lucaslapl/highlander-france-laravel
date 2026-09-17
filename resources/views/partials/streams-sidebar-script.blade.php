<script>
// Encadré sidebar "Streamers en direct" : polling de /api/twitch-sidebar
// (cache alimenté par le CRON app:sync-twitch). Le bloc reste masqué tant
// qu'aucun stream FR TF2 / HL France n'est en ligne.
(function () {
    "use strict";

    var POLL_INTERVAL = 60000;
    var POLL_MAX_INTERVAL = 300000;
    var pollDelay = POLL_INTERVAL;
    var pollTimer = null;
    var box = document.getElementById("twitch-streams");
    var list = document.getElementById("twitch-streams-list");

    if (!box || !list) {
        return;
    }

    function clear() {
        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }
    }

    function render(streams) {
        clear();

        if (!streams.length) {
            box.setAttribute("hidden", "");
            return;
        }

        var fragment = document.createDocumentFragment();

        streams.forEach(function (stream) {
            var li = document.createElement("li");
            var a = document.createElement("a");

            a.className = "twitch-stream";
            a.href = stream.url || ("https://www.twitch.tv/" + (stream.login || ""));
            a.target = "_blank";
            a.rel = "noopener";
            a.title = "Regarder " + (stream.display_name || stream.login || "") + " sur Twitch";

            var top = document.createElement("span");
            top.className = "twitch-stream-top";

            var name = document.createElement("span");
            name.className = "twitch-stream-name";
            name.textContent = stream.display_name || stream.login || "";

            top.appendChild(name);

            if (stream.hl) {
                var badge = document.createElement("span");
                badge.className = "twitch-stream-hl-badge";
                badge.textContent = "HL France";
                top.appendChild(badge);
            }

            a.appendChild(top);

            if (stream.title) {
                var title = document.createElement("span");
                title.className = "twitch-stream-title";
                title.textContent = stream.title;
                a.appendChild(title);
            }

            var viewers = document.createElement("span");
            viewers.className = "twitch-stream-viewers";
            viewers.textContent = (stream.viewers || 0) > 0 ? stream.viewers + " viewers" : "en direct";
            a.appendChild(viewers);

            li.appendChild(a);
            fragment.appendChild(li);
        });

        list.appendChild(fragment);
        box.removeAttribute("hidden");
    }

    function scheduleNext() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(poll, pollDelay);
    }

    function poll() {
        if (document.hidden) {
            scheduleNext();
            return;
        }
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 10000);
        fetch("/api/twitch-sidebar", { signal: controller.signal }).then(function (response) {
            clearTimeout(timeout);
            if (response.status === 429 || response.status >= 500) {
                throw new Error("retryable");
            }
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            pollDelay = POLL_INTERVAL;
            render(payload && Array.isArray(payload.data) ? payload.data : []);
            scheduleNext();
        }).catch(function () {
            clearTimeout(timeout);
            pollDelay = Math.min(pollDelay * 2, POLL_MAX_INTERVAL);
            scheduleNext();
        });
    }

    poll();
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            pollDelay = POLL_INTERVAL;
            poll();
        }
    });
})();
</script>