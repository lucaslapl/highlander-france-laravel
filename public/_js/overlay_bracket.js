/* Overlay bracket / classement (/bracket-overlay/{token}) : construction
   du bracket (cartes, étiquettes, connecteurs animés) et auto-
   rafraîchissement. La page interroge /bracket-overlay/{token}/version
   toutes les 5 s ; chaque enregistrement côté admin — ou chaque événement
   de la série attachée au match « EN DIRECT » — bump la version et la page
   se recharge entièrement, comme l'overlay série. */
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

    var CARD_W = 380;
    var CARD_H = 112;
    var GF_W = 400;
    var GF_H = 292;
    var GAP_X = 130;
    var GAP_Y = 40;
    var MARGIN_X = 90;
    var BANDS = {
        upper: { top: 215, height: 350 },
        lower: { top: 645, height: 350 },
        final: { top: 215, height: 830 }
    };

    var replayGuard = Date.now();

    function build() {
        var host = document.getElementById('bracket');
        if (!host) {
            return;
        }
        host.innerHTML = '';

        var lanes = { upper: [], lower: [], final: [] };
        (data.columns || []).forEach(function (column) {
            var lane = lanes[column.lane] ? column.lane : 'upper';
            lanes[lane].push(column);
        });

        var counter = 0;
        var placed = { upper: [], lower: [], final: [] };

        /* La voie « final » est la dernière colonne du bracket : elle se
           place à droite, après la voie la plus profonde (elle est seule
           dans sa voie, son index interne partirait sinon de la gauche et
           écraserait la première colonne des voies haute et basse). */
        var stride = CARD_W + GAP_X;
        var finalOffset = Math.max(lanes.upper.length, lanes.lower.length);

        ['upper', 'lower', 'final'].forEach(function (lane) {
            lanes[lane].forEach(function (column, depth) {
                var gf = lane === 'final';
                var w = gf ? GF_W : CARD_W;
                var h = gf ? GF_H : CARD_H;
                var x = MARGIN_X + (lane === 'final' ? finalOffset + depth : depth) * stride;
                var n = (column.matches || []).length;
                var total = n * h + Math.max(0, n - 1) * GAP_Y;
                var band = BANDS[lane];
                var top = band.top + (band.height - total) / 2;

                if (n > 0 && !gf) {
                    host.appendChild(label(column.label, lane, x, top - 38, delay(++counter)));
                }

                (column.matches || []).forEach(function (match, i) {
                    var y = top + i * (h + GAP_Y);
                    var card = buildCard(column, match, lane, gf, x, y, w, h, delay(++counter));
                    host.appendChild(card);
                    placed[lane].push({ x: x, y: y, w: w, h: h, depth: depth, index: i, card: card });
                });
            });
        });

        drawConnectors(host, lanes, placed);
    }

    /* Délai d'apparition d'un élément : 1 s d'attente avant que
       l'animation du bracket ne se lance, puis échelonnement de .18 s
       par élément (titre, colonnes, cases, connecteurs). */
    function delay(counter) {
        return (1.25 + counter * 0.18).toFixed(2);
    }

    function label(text, lane, x, y, d) {
        var el = document.createElement('div');
        el.className = 'label anim' + (lane === 'lower' ? ' lower' : '');
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        el.style.setProperty('--d', d + 's');
        el.textContent = text || '';
        return el;
    }

    function buildCard(column, match, lane, gf, x, y, w, h, d) {
        var card = document.createElement('div');
        card.className = 'card anim' + (gf ? ' gf' : '') + (lane === 'lower' ? ' lower' : '');
        card.style.left = x + 'px';
        card.style.top = y + 'px';
        card.style.width = w + 'px';
        card.style.height = h + 'px';
        card.style.setProperty('--d', d + 's');

        if (gf) {
            var head = document.createElement('div');
            head.className = 'gf-head';
            head.textContent = column.label || 'Grand Final';
            card.appendChild(head);
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

            card.appendChild(row);
        });

        if (match.live) {
            var live = document.createElement('div');
            live.className = 'live anim';
            live.style.setProperty('--d', (parseFloat(d) + 0.3).toFixed(2) + 's');
            live.innerHTML = '<span class="dot"></span>EN DIRECT !';
            card.appendChild(live);
        }

        return card;
    }

    /* Connecteurs : au sein d'une voie, chaque case d'une colonne alimente
       la case floor(i/2) de la colonne suivante (câblage standard d'un
       bracket, sans saisie supplémentaire). Le câblage est piloté par les
       sources : toutes les cases d'une colonne reçoivent un trait, y
       compris quand la colonne suivante compte moins de cases que la
       moitié (pré-remplissage manuel, BYE) — les cases en surnombre
       rejoignent alors la dernière case disponible ; les dernières cases
       des voies haute et basse alimentent la grande finale. */
    function drawConnectors(host, lanes, placed) {
        var counter = 0;

        function byDepth(lane, depth) {
            return placed[lane].filter(function (p) { return p.depth === depth; });
        }

        function connect(src, tgt) {
            if (!src || !tgt) {
                return;
            }
            var x1 = src.x + src.w;
            var y1 = src.y + src.h / 2;
            var x3 = tgt.x;
            var y2 = tgt.y + tgt.h / 2;
            var midX = x1 + (x3 - x1) / 2;
            var d = delay(++counter + 12);

            host.appendChild(hline(x1, y1, midX - x1, d));
            if (Math.abs(y2 - y1) > 2) {
                host.appendChild(vline(midX, y1, y2 - y1, d));
            }
            host.appendChild(hline(midX, y2, x3 - midX, d));
        }

        ['upper', 'lower'].forEach(function (lane) {
            for (var depth = 1; depth < lanes[lane].length; depth++) {
                var sources = byDepth(lane, depth - 1);
                var targets = byDepth(lane, depth);
                if (sources.length === 0 || targets.length === 0) {
                    continue;
                }
                sources.forEach(function (src) {
                    connect(src, targets[Math.min(Math.floor(src.index / 2), targets.length - 1)]);
                });
            }
        });

        var finals = byDepth('final', 0);
        if (finals.length > 0) {
            ['upper', 'lower'].forEach(function (lane) {
                var deepest = placed[lane].filter(function (p) {
                    return p.depth === lanes[lane].length - 1;
                });
                if (deepest.length > 0) {
                    connect(deepest[deepest.length - 1], finals[0]);
                }
            });
        }
    }

    function hline(x, y, w, d) {
        var el = document.createElement('div');
        el.className = 'hl';
        el.style.left = x + 'px';
        el.style.top = (y - 1.5) + 'px';
        el.style.width = Math.max(0, w) + 'px';
        el.style.setProperty('--d', d + 's');
        return el;
    }

    function vline(x, y1, dy, d) {
        var el = document.createElement('div');
        el.className = 'vl' + (dy < 0 ? ' up' : '');
        el.style.left = (x - 1.5) + 'px';
        el.style.top = Math.min(y1, y1 + dy) + 'px';
        el.style.height = Math.abs(dy) + 'px';
        el.style.setProperty('--d', d + 's');
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
