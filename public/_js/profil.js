let classesKilledChart = null;
let mapsChart = null;

const CLASS_KILLED_COLORS = {
    scout: '#4fc3f7',
    soldier: '#ef5350',
    pyro: '#ffa726',
    demoman: '#b0a65c',
    heavy: '#e57373',
    engineer: '#a1887f',
    medic: '#66bb6a',
    sniper: '#90a4ae',
    spy: '#ab47bc',
};
const CLASS_KILLED_PALETTE = ['#ff4444', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#e74c3c', '#34495e'];

function classNameLabel(cls) {
    if (!cls) return '';
    return cls.charAt(0).toUpperCase() + cls.slice(1);
}

function renderClassesKilled(data) {
    const container = document.getElementById('classes-killed-container');
    if (!container) return;

    if (classesKilledChart) {
        classesKilledChart.destroy();
        classesKilledChart = null;
    }

    const entries = (data && typeof data === 'object') ? Object.entries(data).filter(([, v]) => Number(v) > 0) : [];

    if (entries.length === 0) {
        container.innerHTML = '<p class="no-data">Aucune donnée de classe tuée pour le moment.</p>';
        return;
    }

    if (typeof Chart === 'undefined') {
        container.innerHTML = '<ul class="stats-list">' + entries.map(([cls, count]) => `
            <li class="flex space-between align-center">
                <div class="flex align-center gap-10">
                    <img src="/_img/classes/${escapeHtml(cls)}.png" alt="${escapeHtml(classNameLabel(cls))}" class="class-icon" title="${escapeHtml(classNameLabel(cls))}">
                </div>
                <span class="stat-value">${count}</span>
            </li>`).join('') + '</ul>';
        return;
    }

    container.innerHTML = `
        <div class="classes-killed-chart">
            <canvas id="classes-killed-chart-canvas"></canvas>
        </div>
        <ul class="classes-killed-legend"></ul>`;

    const labels = entries.map(([cls]) => classNameLabel(cls));
    const values = entries.map(([, c]) => Number(c));
    const colors = entries.map(([cls], i) => CLASS_KILLED_COLORS[cls] || CLASS_KILLED_PALETTE[i % CLASS_KILLED_PALETTE.length]);
    const total = values.reduce((a, b) => a + b, 0);

    classesKilledChart = new Chart(document.getElementById('classes-killed-chart-canvas'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderColor: '#141414',
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.label} : ${ctx.parsed} (${Math.round((ctx.parsed / total) * 100)}%)`,
                    },
                },
            },
        },
    });

    const legend = container.querySelector('.classes-killed-legend');
    if (legend) {
        legend.innerHTML = entries.map(([cls, count]) => `
            <li class="classes-killed-legend__item">
                <img src="/_img/classes/${escapeHtml(cls)}.png" alt="${escapeHtml(classNameLabel(cls))}" class="class-icon" title="${escapeHtml(classNameLabel(cls))}">
                <span class="classes-killed-legend__name">${escapeHtml(classNameLabel(cls))}</span>
                <span class="classes-killed-legend__count">${count}<span class="classes-killed-legend__pct"> (${Math.round((count / total) * 100)}%)</span></span>
            </li>`).join('');
    }
}

function renderMapsChart(data) {
    const container = document.getElementById('maps-container');
    if (!container) return;

    if (mapsChart) {
        mapsChart.destroy();
        mapsChart = null;
    }

    const rows = Array.isArray(data) ? data.filter(r => Number(r.total) > 0) : [];

    if (rows.length === 0) {
        container.innerHTML = '<p class="no-data">Aucune donnée de map pour le moment.</p>';
        return;
    }

    if (typeof Chart === 'undefined') {
        container.innerHTML = '<ul class="stats-list">' + rows.map(m => `
            <li class="flex space-between align-center">
                <span class="stat-label">${escapeHtml(m.map_name)}</span>
                <span class="stat-value">${m.total} match(s)</span>
            </li>`).join('') + '</ul>';
        return;
    }

    const labels = rows.map(m => m.map_name);
    const values = rows.map(m => Number(m.total));
    const height = Math.max(160, rows.length * 30);

    container.innerHTML = `
        <div class="maps-chart" style="height:${height}px;">
            <canvas id="maps-chart-canvas"></canvas>
        </div>`;

    mapsChart = new Chart(document.getElementById('maps-chart-canvas'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: 'rgba(52, 152, 219, 0.8)',
                borderRadius: 4,
                maxBarThickness: 24,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.parsed.x} match(s)`,
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: 'rgba(255, 255, 255, 0.06)' },
                },
                y: {
                    ticks: { font: { size: 11 }, autoSkip: false },
                    grid: { display: false },
                },
            },
        },
    });
}

async function switchProfileMode(button, mode, steamidFallback = null) {
    // 1. Changement visuel de l'onglet actif
    document.querySelectorAll('.profile-tab-btn').forEach(btn => btn.classList.remove('active'));
    button.classList.add('active');

    // 2. Extraction du SteamID64 depuis l'URL de la page
    const urlParams = new URLSearchParams(window.location.search);
    const steamid = steamidFallback || urlParams.get('steamid');

    if (!steamid) {
        console.error("SteamID64 non trouvé dans l'URL.");
        return;
    }

    try {
        // Effet visuel de transition
        document.querySelector('.player-stats').style.opacity = '0.5';

        // Requête vers le fichier d'API de statistiques (à créer à l'étape suivante)
        const response = await fetch(`/api/profile-stats?steamid=${steamid}&mode=${mode}&_=${Date.now()}`);
        if (!response.ok) throw new Error('Erreur réseau');

        const data = await response.json();

        // 3. Remplacement des textes basiques
        document.getElementById('stats-title').innerText = `Stats - ${mode === '6s' ? '6v6' : 'Highlander'}`;
        document.getElementById('recent-title').innerText = `Matchs Récents (${mode === '6s' ? '6v6' : '9v9'})`;
        document.getElementById('stat-total-matches').innerText = data.total_matches;
        document.getElementById('stat-total-damage').innerText = Number(data.average_dpm || 0).toLocaleString('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        document.getElementById('stat-total-kills').innerText = data.total_kills || 0;
        document.getElementById('stat-total-deaths').innerText = data.total_deaths || 0;
        document.getElementById('stat-kd-ratio').innerText = data.kd_ratio || 0;
        document.getElementById('stat-combat-airshots').innerText = data.total_airshots || 0;

        // 4. Graphique à barres des Maps jouées
        renderMapsChart(data.top_maps);

        // 5. Remplacement du tableau des Classes
        const classesContainer = document.getElementById('classes-container');
        if (!data.classes_played || data.classes_played.length === 0) {
            classesContainer.innerHTML = '<p class="no-data">Aucune donnée de classe pour ce mode.</p>';
        } else {
            let html = '<ul class="stats-list">';
            data.classes_played.forEach(cls => {
                const className = escapeHtml(cls.class_played);
                html += `
                    <li class="flex space-between align-center">
                        <div class="flex align-center gap-10">
                            <img src="/_img/classes/${className}.png" alt="${className}" class="class-icon" title="${className}">
                        </div>
                        <span class="stat-value">${cls.total}</span>
                    </li>`;
            });
            html += '</ul>';
            classesContainer.innerHTML = html;
        }

        // 5bis. Camembert des Classes tuées
        renderClassesKilled(data.classes_killed);

        // 6. Remplacement des Matchs Récents
        const recentContainer = document.getElementById('recent-container');
        if (!data.recent_matches || data.recent_matches.length === 0) {
            recentContainer.innerHTML = '<p class="no-data">Aucun match enregistré pour le moment dans ce mode.</p>';
        } else {
            let html = `<table class="matches-table">
                            <thead>
                                <tr>
                                    <th>Classe</th>
                                    <th>Résultat</th>
                                    <th>Map</th>
                                    <th>K/D/A</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>`;
            data.recent_matches.forEach(match => {
                const mId = escapeHtml(match.match_id);
                const cPlayed = escapeHtml(match.class_played);
                const won = (match.won === 1 || match.won === '1') ? 'win' : ((match.won === 0 || match.won === '0') ? 'loss' : 'unknown');
                const resultClass = `result-${won}`;
                const resultLabel = won === 'win' ? 'Victoire' : (won === 'loss' ? 'Défaite' : '—');
                html += `
                    <tr class="match-row" data-href="/log/${mId}">
                        <td data-label="Classe">
                            <img src="/_img/classes/${cPlayed}.png" alt="${cPlayed}" class="class-icon" title="Joué en ${cPlayed}">
                            <span>${cPlayed.charAt(0).toUpperCase() + cPlayed.slice(1)}</span>
                        </td>
                        <td data-label="Résultat"><span class="match-result ${resultClass}">${resultLabel}</span></td>
                        <td data-label="Map">${escapeHtml(match.map_name)}</td>
                        <td data-label="K/D/A">${match.kills || 0} / ${match.deaths || 0} / ${match.assists || 0}</td>
                        <td data-label="Date">${escapeHtml(match.match_date || '—')}</td>
                    </tr>`;
            });
            html += '</tbody></table>';
            recentContainer.innerHTML = html;
        }

    } catch (error) {
        console.error("Erreur lors de la mise à jour des statistiques:", error);
    } finally {
        document.querySelector('.player-stats').style.opacity = '1';
    }
}

function renderActivityCalendar(data) {
    const container = document.getElementById('activity-grid');
    if (!container) return;

    const map = (data && typeof data === 'object') ? data : {};

    // Clés de dates UTC pour correspondre à strftime('%Y-%m-%d', date, 'unixepoch') côté SQL
    const pad = (n) => String(n).padStart(2, '0');
    const dateKey = (d) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;

    // Semaine courante commençant un dimanche, fenêtre de 3 mois en arrière.
    // Tout est calculé en UTC pour coller aux clés produites par le SQL.
    const now = new Date();
    const today = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
    const endWeek = new Date(today);
    endWeek.setUTCDate(today.getUTCDate() - today.getUTCDay());
    const start = new Date(endWeek);
    start.setUTCMonth(start.getUTCMonth() - 3);
    // Recalage sur le dimanche de cette semaine-là (pour aligner les lignes Lun/Mer/Ven)
    start.setUTCDate(start.getUTCDate() - start.getUTCDay());

    // Nombre de colonnes : +1 pour inclure la semaine courante (sinon les matchs
    // les plus récents sont tronqués par l'arrondi).
    const weeks = Math.max(1, Math.round((endWeek - start) / (7 * 86400000)) + 1);

    // Seuils par quartiles des jours non nuls (niveau de couleur)
    const counts = Object.values(map).filter(v => v > 0).sort((a, b) => a - b);
    const n = counts.length;
    const q = (p) => n === 0 ? 0 : counts[Math.min(n - 1, Math.floor(p * n))];
    const t1 = q(0.25), t2 = q(0.5), t3 = q(0.75);

    const level = (c) => {
        if (!c) return 0;
        if (c <= t1) return 1;
        if (c <= t2) return 2;
        if (c <= t3) return 3;
        return 4;
    };

    const total = Object.values(map).reduce((a, b) => a + b, 0);

    // Libellés de mois au-dessus des semaines (chacun s'étale sur ses semaines)
    let months = '';
    let lastMonth = -1;
    let monthStart = 0;
    let monthLabel = '';
    const mCursor = new Date(start);
    for (let w = 0; w < weeks; w++) {
        const m = mCursor.getUTCMonth();
        if (m !== lastMonth) {
            if (lastMonth !== -1) {
                months += `<span style="grid-column: ${monthStart} / ${w + 1}">${monthLabel}</span>`;
            }
            monthLabel = mCursor.toLocaleDateString('fr-FR', { month: 'short', timeZone: 'UTC' });
            monthStart = w + 1;
            lastMonth = m;
        }
        mCursor.setUTCDate(mCursor.getUTCDate() + 7);
    }
    if (lastMonth !== -1) {
        months += `<span style="grid-column: ${monthStart} / ${weeks + 1}">${monthLabel}</span>`;
    }
    const monthsContainer = document.getElementById('activity-months');
    monthsContainer.style.gridTemplateColumns = `repeat(${weeks}, 1fr)`;
    monthsContainer.innerHTML = months;

    // Jours de la semaine (colonne de gauche : Lun, Mer, Ven)
    const dayNames = [
        ['Lun', 1], ['Mer', 3], ['Ven', 5]
    ];
    document.getElementById('activity-days').innerHTML = dayNames.map(([label, row]) =>
        `<span style="grid-row: ${row + 1}">${label}</span>`
    ).join('');

    // Cellules de la grille : N colonnes (semaines) x 7 lignes (jours)
    let cells = '';
    const d = new Date(start);
    for (let w = 0; w < weeks; w++) {
        for (let day = 0; day < 7; day++) {
            const key = dateKey(d);
            const count = map[key] || 0;
            cells += `<div class="activity-calendar__cell activity-calendar__cell--${level(count)}" data-date="${key}" data-count="${count}"></div>`;
            d.setUTCDate(d.getUTCDate() + 1);
        }
    }
    container.style.gridTemplateColumns = `repeat(${weeks}, 10px)`;
    container.innerHTML = cells;

    const summary = document.getElementById('activity-summary');
    if (summary) {
        summary.textContent = total > 0
            ? `${total} match${total > 1 ? 's' : ''} au cours des 3 derniers mois.`
            : 'Aucun match au cours des 3 derniers mois.';
    }

    // Info-bulle personnalisée au survol des cases
    const tooltip = document.getElementById('activity-tooltip');
    if (tooltip && container) {
        container.addEventListener('mouseover', (e) => {
            const cell = e.target.closest('.activity-calendar__cell');
            if (!cell) return;
            const count = Number(cell.dataset.count) || 0;
            const parts = (cell.dataset.date || '').split('-');
            const day = parts.length === 3 ? `${parts[2]}/${parts[1]}` : '';
            tooltip.textContent = count > 0
                ? `${count} match${count > 1 ? 's' : ''} le ${day}`
                : 'Aucun match';
            tooltip.classList.add('is-visible');
        });

        container.addEventListener('mousemove', (e) => {
            const box = document.querySelector('.activity-calendar');
            if (!box) return;
            const rect = box.getBoundingClientRect();
            tooltip.style.left = (e.clientX - rect.left + 14) + 'px';
            tooltip.style.top = (e.clientY - rect.top + 14) + 'px';
        });

        container.addEventListener('mouseout', (e) => {
            if (!e.relatedTarget || !e.relatedTarget.closest('.activity-calendar__cell')) {
                tooltip.classList.remove('is-visible');
            }
        });
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
}

// Rendu initial du camembert "Classes tuées" (données injectées par PHP)
if (typeof window.__initialClassesKilled !== 'undefined') {
    renderClassesKilled(window.__initialClassesKilled);
}

// Rendu initial du graphique à barres "Maps jouées" (données injectées par PHP)
if (typeof window.__initialTopMaps !== 'undefined') {
    renderMapsChart(window.__initialTopMaps);
}

// Rendu initial du calendrier d'activité (données injectées par PHP)
if (typeof window.__activityData !== 'undefined') {
    renderActivityCalendar(window.__activityData);
}

// Clic sur une ligne du tableau des matchs récents → ouvre la page détail du log
document.addEventListener('click', function (event) {
    const row = event.target.closest('.match-row');
    if (row && row.dataset.href) {
        window.open(row.dataset.href, '_blank');
    }
});