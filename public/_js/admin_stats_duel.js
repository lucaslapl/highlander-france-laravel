(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]');
    const TOKEN = CSRF ? CSRF.getAttribute('content') : '';

    const progressBox = document.getElementById('sd-progress');
    const progressFill = document.getElementById('sd-progress-fill');
    const progressPct = document.getElementById('sd-progress-pct');
    const progressMessage = document.getElementById('sd-progress-message');
    const progressError = document.getElementById('sd-progress-error');
    const progressLabel = document.getElementById('sd-progress-label');
    const resultsBox = document.getElementById('sd-results');
    const form = document.getElementById('sd-form');
    const runFlag = document.getElementById('sd-run');

    let polling = false;

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
                return r.json().then(function (err) {
                    throw new Error((err && err.error) || 'HTTP ' + r.status);
                });
            }
            return r.json();
        });
    }

    function getJson(url) {
        return fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) {
                return r.json().then(function (err) {
                    throw new Error((err && err.error) || 'HTTP ' + r.status);
                });
            }
            return r.json();
        });
    }

    function setProgress(percent, message) {
        const p = Math.max(0, Math.min(100, percent));
        if (progressPct) progressPct.textContent = p + ' %';
        if (progressFill) progressFill.style.width = p + '%';
        if (progressMessage && message) progressMessage.textContent = message;
    }

    function showError(msg) {
        if (progressError) {
            progressError.textContent = msg;
            progressError.style.display = 'block';
        }
        if (progressLabel) {
            progressLabel.textContent = 'Échec du calcul';
        }
    }

    function resetProgressLabel() {
        if (progressLabel) {
            progressLabel.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Calcul en cours…';
        }
        if (progressError) progressError.style.display = 'none';
    }

    function finalize(runId) {
        getJson('/admin/stats-duel/result/' + runId).then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || 'Résultat indisponible.');
            }
            resultsBox.innerHTML = data.html;
            bindCardHandlers();
            progressBox.style.display = 'none';
            progressError.style.display = 'none';
            polling = false;
        }).catch(function (err) {
            polling = false;
            showError(err.message || 'Impossible de récupérer le résultat.');
        });
    }

    function poll(runId) {
        const doPoll = function () {
            if (!polling) return;
            getJson('/admin/stats-duel/progress/' + runId).then(function (data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Run introuvable.');
                }
                showProgress();
                setProgress(data.progress, data.message);

                if (data.status === 2) {
                    polling = false;
                    setProgress(100, 'Terminé');
                    finalize(runId);
                    return;
                }
                if (data.status === 3) {
                    polling = false;
                    showError(data.message || 'Erreur pendant le calcul.');
                    return;
                }
                setTimeout(doPoll, 1000);
            }).catch(function (err) {
                polling = false;
                showError(err.message || 'Erreur de progression.');
            });
        };
        polling = true;
        doPoll();
    }

    function showProgress() {
        progressBox.style.display = 'block';
    }

    // Visibilité « joue le jour du match » + filtre, pour les cartes injectées
    // comme pour celles du chargement initial.
    function bindCardHandlers() {
        const boxes = document.querySelectorAll('.sd-team');
        boxes.forEach(function (teamCard) {
            teamCard.querySelectorAll('.sd-player-vis').forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    const row = checkbox.closest('.sd-player-row');
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

            teamCard.querySelectorAll('.sd-only-playing').forEach(function (toggle) {
                toggle.addEventListener('change', function () {
                    const table = teamCard.querySelector('.sd-players');
                    if (table) {
                        table.classList.toggle('sd-hide-unchecked', toggle.checked);
                    }
                });
            });
        });
    }

    // Soumission du formulaire en AJAX (pas de rechargement de page).
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const data = {
                team_1: form.elements.team_1.value,
                team_2: form.elements.team_2.value,
                mode_1: form.elements.mode_1.value,
                mode_2: form.elements.mode_2.value,
            };
            resultsBox.innerHTML = '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Calcul…';
            }
            resetProgressLabel();
            setProgress(0, 'Lancement du calcul…');

            post('/admin/stats-duel/run-async', data)
                .then(function (resp) {
                    if (!resp.ok || !resp.run_id) {
                        throw new Error((resp && resp.error) || 'Impossible de lancer le calcul.');
                    }
                    history.replaceState(null, '', '/admin/stats-duel?run=' + resp.run_id);
                    showProgress();
                    poll(resp.run_id);
                })
                .catch(function (err) {
                    showError(err.message || 'Impossible de lancer le calcul.');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-calculator"></i> Calculer';
                    }
                });
        });
    }

    // Page chargée avec ?run=... : on interroge ce run (rechargement).
    if (runFlag) {
        const existingRun = runFlag.getAttribute('data-run-id');
        if (existingRun) {
            showProgress();
            setProgress(0, 'Reprise du calcul…');
            poll(parseInt(existingRun, 10));
        }
    }

    bindCardHandlers();
})();