/* Overlay OBS (/overlay/{token}) : auto-rafraîchissement.
   La page interroge /overlay/{token}/version toutes les 5 s ; si l'admin a
   régénéré ou modifié l'overlay depuis le panel, la page se recharge
   entièrement — OBS n'a donc jamais besoin d'être touché en direct. */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var token = script.dataset.token;
    var version = String(script.dataset.version);

    /* Animation d'apparition : rejouée quand OBS rend la source visible/active
       (API window.obsstudio exposée par obs-browser). Dans un navigateur
       classique, l'animation CSS joue une fois au chargement et c'est tout. */
    var lastReplay = Date.now();

    function replayEnterAnimation() {
        var stage = document.querySelector('.overlay-stage');
        if (!stage) {
            return;
        }

        /* Anti-rebond : obs-browser peut signaler visibilité ET activation dans
           la même seconde ; on ignore aussi les rejeux trop rapprochés. */
        var now = Date.now();
        if (now - lastReplay < 2500) {
            return;
        }
        lastReplay = now;

        /* Couper les animations puis les réactiver (avec un reflow entre les
           deux) force le navigateur à les rejouer depuis le début. */
        stage.classList.add('overlay-stage--no-anim');
        void stage.offsetWidth;
        stage.classList.remove('overlay-stage--no-anim');
    }

    if (window.obsstudio) {
        window.obsstudio.onVisibilityChange = function (visible) {
            if (visible) {
                replayEnterAnimation();
            }
        };
        window.obsstudio.onActiveChange = function (active) {
            if (active) {
                replayEnterAnimation();
            }
        };
    }

    /* Filet de sécurité : obs-browser passe aussi la page en visibilityState
       hidden/visible quand la source cesse ou reprend le rendu. */
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            replayEnterAnimation();
        }
    });

    /* Tri interactif des stats : clic sur un en-tête de colonne (via la
       fenêtre « Interagir » d'OBS) réordonne les lignes des DEUX tableaux
       dans le même sens, pour comparer les équipes colonne par colonne.
       Déplacement des lignes animé (technique FLIP) + flash sur la colonne. */
    function initStatsSorting() {
        var headers = document.querySelectorAll('.overlay-stats thead th[data-key]');
        if (headers.length === 0) {
            return;
        }

        var currentKey = null;
        var currentDir = 'desc';

        function compareRows(key, dir) {
            var sign = dir === 'asc' ? 1 : -1;

            if (key === 'name') {
                return function (a, b) {
                    return sign * String(a.dataset.name).localeCompare(String(b.dataset.name), 'fr', { sensitivity: 'base' });
                };
            }

            return function (a, b) {
                var va = parseFloat(a.dataset[key] || '') || 0;
                var vb = parseFloat(b.dataset[key] || '') || 0;
                return sign * (va === vb ? 0 : (va < vb ? -1 : 1));
            };
        }

        /* FLIP : on mémorise la position de chaque ligne, on les déplace dans
           le DOM, puis on repart de leur delta pour une transition fluide. */
        function sortTable(table, key, dir) {
            var tbody = table.tBodies[0];
            if (!tbody) {
                return;
            }

            var rows = Array.prototype.slice.call(tbody.rows);
            var tops = new Map();
            rows.forEach(function (row) {
                tops.set(row, row.getBoundingClientRect().top);
            });

            rows.sort(compareRows(key, dir));
            rows.forEach(function (row) {
                tbody.appendChild(row);
            });

            rows.forEach(function (row) {
                var delta = tops.get(row) - row.getBoundingClientRect().top;
                if (Math.abs(delta) < 1) {
                    return;
                }

                row.style.transition = 'none';
                row.style.transform = 'translateY(' + delta + 'px)';
                row.getBoundingClientRect();
                row.style.transition = 'transform 0.35s cubic-bezier(0.22, 1, 0.36, 1)';
                row.style.transform = '';

                row.addEventListener('transitionend', function () {
                    row.style.transition = '';
                }, { once: true });
            });
        }

        /* Flash bref de la colonne triée : la classe .col-flash porte une
           animation CSS ; on la repose pour pouvoir la rejouer au prochain tri. */
        function flashSortedColumn(table, cellIndex) {
            var tbody = table.tBodies[0];
            if (!tbody) {
                return;
            }

            Array.prototype.forEach.call(tbody.rows, function (row) {
                var cell = row.cells[cellIndex];
                if (!cell) {
                    return;
                }
                cell.classList.remove('col-flash');
                void cell.offsetWidth;
                cell.classList.add('col-flash');
            });
        }

        Array.prototype.forEach.call(headers, function (th) {
            th.addEventListener('click', function () {
                var key = th.dataset.key;
                if (!key) {
                    return;
                }

                if (key === currentKey) {
                    currentDir = currentDir === 'desc' ? 'asc' : 'desc';
                } else {
                    currentKey = key;
                    currentDir = key === 'name' ? 'asc' : 'desc';
                }

                Array.prototype.forEach.call(headers, function (head) {
                    if (head.dataset.key === currentKey) {
                        head.classList.add('is-sorted');
                        head.classList.toggle('is-sorted--asc', currentDir === 'asc');
                        head.classList.toggle('is-sorted--desc', currentDir === 'desc');
                    } else {
                        head.classList.remove('is-sorted', 'is-sorted--asc', 'is-sorted--desc');
                    }
                });

                Array.prototype.forEach.call(document.querySelectorAll('.overlay-stats'), function (table) {
                    sortTable(table, key, currentDir);
                    flashSortedColumn(table, th.cellIndex);
                });
            });
        });
    }

    initStatsSorting();

    if (!token) {
        return;
    }

    setInterval(function () {
        fetch('/overlay/' + token + '/version', { cache: 'no-store' })
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
})();
