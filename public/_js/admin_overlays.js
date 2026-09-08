(function () {
    'use strict';

    var typeSel = document.getElementById('ov-type');
    var targetWrap = document.getElementById('ov-target-wrap');
    var targetSel = document.getElementById('ov-target');
    var transparent = document.getElementById('ov-transparent');
    var categoryWrap = document.getElementById('ov-category-wrap');
    var categorySel = document.getElementById('ov-category');
    var urlBox = document.getElementById('ov-url');
    var frame = document.getElementById('ov-frame');
    var copyBtn = document.getElementById('ov-copy');

    var DATA = window.OV_DATA || {};

    var options = {
        equipe: function () {
            var list = [];
            DATA.franceTeams.forEach(function (t) {
                list.push({ value: t.team_id, label: t.name + ' (' + (t.category.toUpperCase()) + ')', category: t.category });
            });
            DATA.teams.forEach(function (t) {
                list.push({ value: t.team_id, label: t.name + ' (ETF2L)' });
            });
            return {
                list: list,
                build: function (v) {
                    var opt = targetSel.options[targetSel.selectedIndex];
                    var cat = categorySel.value;
                    if (opt && opt.dataset && opt.dataset.category) {
                        cat = opt.dataset.category;
                    }
                    return '/overlay/equipe/' + v + '?category=' + (cat || '9v9');
                }
            };
        },
        match: {
            list: DATA.matches.map(function (m) { return { value: m.match_id, label: '#' + m.match_id + ' · ' + m.label }; }),
            build: function (v) { return '/overlay/match/' + v; }
        },
        joueur: {
            list: DATA.players.filter(function (p) { return p.steamid64; }).map(function (p) { return { value: p.steamid64, label: p.name }; }),
            build: function (v) { return '/overlay/joueur/' + v; }
        },
        scoreboard: {
            list: DATA.logs.map(function (l) { return { value: l.log_id, label: '#' + l.log_id + ' (' + l.category + ')' }; }),
            build: function (v) { return '/overlay/scoreboard/' + v; }
        }
    };

    function currentDefs() {
        var d = options[typeSel.value];
        return (d && typeof d === 'function') ? d() : d;
    }

    function refresh() {
        var defs = currentDefs();
        targetSel.innerHTML = '';

        var showCategory = typeSel.value === 'equipe';
        categoryWrap.style.display = showCategory ? '' : 'none';
        categorySel.value = '9v9';

        var empty = !defs || !defs.list || defs.list.length === 0;
        targetSel.disabled = empty;
        if (empty) {
            var none = document.createElement('option');
            none.value = '';
            none.textContent = 'Aucune cible disponible';
            targetSel.appendChild(none);
            setUrl('');
            return;
        }
        defs.list.forEach(function (item) {
            var o = document.createElement('option');
            o.value = item.value;
            o.textContent = item.label;
            if (item.category) o.dataset.category = item.category;
            targetSel.appendChild(o);
        });
        var first = targetSel.options[0];
        if (first && first.dataset && first.dataset.category) {
            categorySel.value = first.dataset.category;
        }
        setUrl(defs.build(targetSel.value));
    }

    function setUrl(path) {
        if (!path) { urlBox.textContent = ''; frame.setAttribute('src', 'about:blank'); return; }
        var base = path.split('?')[0];
        var params = new URLSearchParams(path.split('?')[1] || '');
        if (transparent.checked) { params.set('bg', 'transparent'); }
        var q = params.toString();
        var url = window.location.origin + base + (q ? '?' + q : '');
        urlBox.textContent = url;
        frame.setAttribute('src', url);
    }

    typeSel.addEventListener('change', refresh);
    targetSel.addEventListener('change', function () { setUrl(currentDefs().build(this.value)); });
    categorySel.addEventListener('change', function () { setUrl(currentDefs().build(targetSel.value)); });
    transparent.addEventListener('change', function () { setUrl(currentDefs().build(targetSel.value)); });

    copyBtn.addEventListener('click', function () {
        var text = urlBox.textContent;
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { flashCopied(); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            flashCopied();
        }
    });

    function flashCopied() {
        var original = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Copié !';
        setTimeout(function () { copyBtn.innerHTML = original; }, 1500);
    }

    refresh();
})();