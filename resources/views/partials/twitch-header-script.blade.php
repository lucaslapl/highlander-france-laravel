<script>
// Lecteur Twitch 3D du header (accueil) : polling de /api/twitch-live
// (cache alimenté par le CRON app:sync-twitch). Live -> embed canal,
// sinon dernière VOD. L'iframe n'est reconstruite que si la source change.
(function () {
    "use strict";

    var POLL_INTERVAL = 60000;
    var DEFAULT_CHANNEL = "highlanderfrance";
    var container = document.getElementById("twitch-header-embed");
    var holder = document.getElementById("twitch-embed");

    if (!container || !holder) {
        return;
    }

    var currentSrc = "";

    // Twitch exige au moins un parent correspondant au domaine hôte de la page.
    function parentsParams() {
        var hosts = [window.location.hostname, "highlanderfrance.tf"];
        var seen = {};
        var params = "";
        hosts.forEach(function (host) {
            if (host && !seen[host]) {
                seen[host] = true;
                params += "&parent=" + encodeURIComponent(host);
            }
        });
        return params;
    }

    function buildSrc(embed) {
        if (embed && embed.live && embed.channel) {
            return "https://player.twitch.tv/?channel=" + encodeURIComponent(embed.channel)
                + "&muted=true&autoplay=true" + parentsParams();
        }

        // Hors-ligne : dernière VOD si connue, sinon repli sur l'embed canal
        // (Twitch y affiche lui-même son écran hors-ligne).
        if (embed && embed.video_id) {
            return "https://player.twitch.tv/?video=" + encodeURIComponent(embed.video_id)
                + "&muted=true&autoplay=true" + parentsParams();
        }

        return "https://player.twitch.tv/?channel="
            + encodeURIComponent((embed && embed.channel) || DEFAULT_CHANNEL)
            + "&muted=true" + parentsParams();
    }

    function mount(src) {
        if (!src || src === currentSrc) {
            return;
        }
        currentSrc = src;

        var iframe = document.createElement("iframe");
        iframe.src = src;
        iframe.title = "Highlander France sur Twitch";
        iframe.allowFullscreen = true;

        while (holder.firstChild) {
            holder.removeChild(holder.firstChild);
        }
        holder.appendChild(iframe);
    }

    function poll() {
        fetch("/api/twitch-live").then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            mount(buildSrc(payload && payload.data ? payload.data.embed || null : null));
        }).catch(function () {
            // Réseau/API indisponible : on garde la source courante telle quelle,
            // sauf si rien n'a encore été monté (premier chargement).
            if (!currentSrc) {
                mount(buildSrc(null));
            }
        });
    }

    poll();
    setInterval(poll, POLL_INTERVAL);
})();
</script>
