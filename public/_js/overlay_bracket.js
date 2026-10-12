/* Overlay bracket / classement (/bracket-overlay/{token}) : construction
   du bracket et auto-rafraîchissement. La page interroge
   /bracket-overlay/{token}/version toutes les 5 s ; chaque enregistrement
   côté admin — ou chaque événement de la série attachée au match « EN
   DIRECT » — bump la version et la page se recharge entièrement, comme
   l'overlay série.

   Le layout des cases, étiquettes et connecteurs est calculé par le
   moteur partagé bracket_layout.js (HlfrBracket.layout) : positions
   dérivées de l'arbre, connecteurs en un seul chemin SVG, mise à
   l'échelle automatique vers la zone utile 1920x1080 sous le titre —
   n'importe quelle forme de bracket s'affiche sans constante à retoucher.
   Cette page ne fait que poser les éléments (cartes HTML, chemins SVG)
   avec l'échelonnement d'apparition. */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var token = script.dataset.token;
    var version = String(script.dataset.version);

    /* ── Auto-rafraîchissement (bracket et classement) ─────────────────── */

    if (token) {
        setInterval(function () {
            fetch('/bracket-overlay/' + token + '/version', { cache: 'no-store' })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (data && String(data.version) !== version) {
                        window.location.reload();
                    }
                })
                .catch(function () {
                    /* Réseau indisponible : on retentera au prochain tick. */
                });
        }, 5000);
    }

    /* ── Construction du bracket ──────────────────────────────────────── */

    var dataEl = document.getElementById('bracket-data');
    if (!dataEl) {
        return; /* Overlay classement : rien à construire, le HTML est servi. */
    }

    var data;
    try {
        data = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return;
    }

    /* Zone utile sous le titre (1920x1080, header ~215 px) : le bracket
       s'y inscrit centré, à l'échelle, sans agrandissement (maxScale 1). */
    var VIEWPORT = { x: 90, y: 215, w: 1740, h: 835 };

    var replayGuard = Date.now();
    var counter = 0;

    /* Délai d'apparition d'un élément : 1 s d'attente avant que
       l'animation du bracket ne se lance, puis échelonnement de .18 s
       par élément (étiquettes, cases, connecteurs). */
    function delay() {
        counter += 1;
        return (1.25 + counter * 0.18).toFixed(2);
    }

    function build() {
        var host = document.getElementById('bracket');
        if (!host) {
            return;
        }
        host.innerHTML = '';
        counter = 0;

        var result = HlfrBracket.layout(data);
        if (result.cards.length === 0) {
            return;
        }

        var f = HlfrBracket.fit(result.bounds, VIEWPORT);

        var wrap = document.createElement('div');
        wrap.className = 'wrap';
        wrap.style.width = result.bounds.w + 'px';
        wrap.style.height = result.bounds.h + 'px';
        wrap.style.transform = 'translate(' + f.x + 'px, ' + f.y + 'px) scale(' + f.scale + ')';

        /* Connecteurs : un seul <path> par lien (H-V-H), posés sous les
           cartes. pathLength=1 normalise la longueur : l'animation de
           tracé par stroke-dashoffset fonctionne quelle que soit la
           longueur réelle du chemin. */
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'lines');
        svg.setAttribute('width', String(result.bounds.w));
        svg.setAttribute('height', String(result.bounds.h));
        svg.setAttribute('viewBox', '0 0 ' + result.bounds.w + ' ' + result.bounds.h);
        wrap.appendChild(svg);

        result.labels.forEach(function (label) {
            var el = document.createElement('div');
            el.className = 'label anim' + (label.lane === 'lower' ? ' lower' : '');
            el.style.left = label.x + 'px';
            el.style.top = label.y + 'px';
            el.style.setProperty('--d', delay() + 's');
            el.textContent = label.text || '';
            wrap.appendChild(el);
        });

        result.cards.forEach(function (card) {
            wrap.appendChild(buildCard(card));
        });

        /* Les connecteurs suivent les cases avec un léger décalage
           (12 rangs), comme la version précédente. */
        counter += 12;
        result.links.forEach(function (link) {
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', link.d);
            path.setAttribute('pathLength', '1');
            path.setAttribute('class', 'link');
            path.style.setProperty('--d', delay() + 's');
            svg.appendChild(path);
        });

        host.appendChild(wrap);
    }

    function buildCard(card) {
        var match = card.match;
        var column = card.column;

        var el = document.createElement('div');
        el.className = 'card anim'
            + (card.gf ? ' gf' : '')
            + (card.lane === 'lower' ? ' lower' : '');
        el.style.left = card.x + 'px';
        el.style.top = card.y + 'px';
        el.style.width = card.w + 'px';
        el.style.height = card.h + 'px';
        el.style.setProperty('--d', delay() + 's');

        if (card.gf) {
            var head = document.createElement('div');
            head.className = 'gf-head';
            head.textContent = column.label || 'Grand Final';
            el.appendChild(head);
        }

        (match.teams || []).forEach(function (team) {
            var row = document.createElement('div');
            row.className = 'team ' + (team.status || 'pending');

            if (team.avatar) {
                var avatar = document.createElement('img');
                avatar.className = 'avatar';
                avatar.src = team.avatar;
                avatar.alt = '';
                row.appendChild(avatar);
            }

            var name = document.createElement('span');
            name.className = 'name';
            name.textContent = team.name || '';
            row.appendChild(name);

            var score = document.createElement('span');
            score.className = 'score';
            score.textContent = team.score === '' || team.score === null ? '–' : team.score;
            row.appendChild(score);

            el.appendChild(row);
        });

        if (match.live) {
            var live = document.createElement('div');
            live.className = 'live anim';
            live.style.setProperty('--d', (parseFloat(el.style.getPropertyValue('--d')) + 0.3).toFixed(2) + 's');
            live.innerHTML = '<span class="dot"></span>EN DIRECT !';
            el.appendChild(live);
        }

        return el;
    }

    /* Animation d'entrée rejouée quand OBS rend la source visible/active
       (ou au clic, pour prévisualiser dans un navigateur). */
    function replay() {
        var now = Date.now();
        if (now - replayGuard < 2500) {
            return;
        }
        replayGuard = now;
        build();
    }

    if (window.obsstudio) {
        window.obsstudio.onVisibilityChange = function (visible) {
            if (visible) { replay(); }
        };
        window.obsstudio.onActiveChange = function (active) {
            if (active) { replay(); }
        };
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { replay(); }
    });

    document.addEventListener('click', replay);

    build();
})();
