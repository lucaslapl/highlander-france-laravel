<script>
// Badge "EN DIRECT" Twitch : polling de /api/twitch-live (cache alimenté par
// le CRON app:sync-twitch). Chaque poll reconstruit entièrement l'état :
// badges masqués/affichés et bannière générique retirée/redessinée.
(function () {
    "use strict";

    var POLL_INTERVAL = 60000;
    var badgeByMatchId = {};
    var banner = null;

    function collectBadges() {
        document.querySelectorAll("[data-match-id] .badge-twitch-live, .badge-twitch-live[data-match-id]").forEach(function (badge) {
            var holder = badge.closest("[data-match-id]");
            var id = parseInt(holder ? holder.getAttribute("data-match-id") : badge.getAttribute("data-match-id"), 10);
            if (!isNaN(id)) {
                badgeByMatchId[id] = badge;
            }
        });
    }

    function removeBanner() {
        if (banner && banner.parentNode) {
            banner.parentNode.removeChild(banner);
        }
        banner = null;
    }

    function render(channels) {
        // Map matchId -> {url, display_name} (première chaîne rencontrée gagne).
        var byMatchId = {};
        channels.forEach(function (channel) {
            (channel.matched_match_ids || []).forEach(function (id) {
                if (!(id in byMatchId)) {
                    byMatchId[id] = { url: channel.url, name: channel.display_name };
                }
            });
        });

        Object.keys(badgeByMatchId).forEach(function (id) {
            var badge = badgeByMatchId[id];
            if (byMatchId[id]) {
                badge.href = byMatchId[id].url;
                badge.title = "Regarder sur Twitch (" + byMatchId[id].name + ")";
                badge.removeAttribute("hidden");
            } else {
                badge.setAttribute("hidden", "");
            }
        });

        removeBanner();

        // Bannière de secours : au moins une chaîne live mais aucune (ou une
        // seule ambiguë) association avec un match.
        var unmatched = channels.filter(function (channel) {
            return !(channel.matched_match_ids || []).length && channel.url;
        });

        if (unmatched.length) {
            var list = document.querySelector(".etf2l-agenda-container");
            if (list) {
                banner = document.createElement("a");
                banner.className = "twitch-live-banner";
                banner.href = unmatched[0].url;
                banner.target = "_blank";
                banner.rel = "noopener";
                banner.textContent = "\uD83D\uDD34 Un match est stream\u00E9 en direct sur Twitch \u2014 " + (unmatched[0].display_name || unmatched[0].login || "cha\u00EEne inconnue") + (unmatched.length > 1 ? " (+ " + (unmatched.length - 1) + ")" : "");
                list.insertBefore(banner, list.firstChild);
            }
        }
    }

    function poll() {
        fetch("/api/twitch-live").then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            render(payload && payload.data ? payload.data.channels || [] : []);
        }).catch(function () {
            // Réseau/API indisponible : on garde l'état courant tel quel.
        });
    }

    collectBadges();
    poll();
    setInterval(poll, POLL_INTERVAL);
})();
</script>
