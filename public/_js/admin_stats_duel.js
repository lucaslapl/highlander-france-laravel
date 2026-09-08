(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]');
    const TOKEN = CSRF ? CSRF.getAttribute('content') : '';

    function post(url, data) {
        const body = new URLSearchParams();
        Object.keys(data).forEach(function (k) {
            body.set(k, data[k]);
        });

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': TOKEN,
            },
            body: body.toString(),
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        });
    }

    // Visibilité « joue le jour du match » : masque le joueur et persiste stat_lineup.
    document.querySelectorAll('.sd-player-vis').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const row = checkbox.closest('.sd-player-row');
            const teamCard = checkbox.closest('.sd-team');
            if (!row || !teamCard) {
                return;
            }

            const teamId = teamCard.getAttribute('data-team-id');
            const steamid = row.getAttribute('data-steamid');
            const visible = checkbox.checked;

            row.classList.toggle('is-hidden', !visible);

            post('/admin/stats-duel/toggle-player', {
                team_id: teamId,
                steamid: steamid,
                visible: visible ? '1' : '0',
            }).catch(function () {
                checkbox.checked = !visible;
                row.classList.toggle('is-hidden', visible);
            });
        });
    });

    // Filtre « n'afficher que les joueurs cochés ».
    document.querySelectorAll('.sd-only-playing').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            const table = toggle.closest('.sd-team').querySelector('.sd-players');
            if (table) {
                table.classList.toggle('sd-hide-unchecked', toggle.checked);
            }
        });
    });
})();
