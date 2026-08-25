let currentMode = '9v9';
let currentCategory = 'matches';

const categoryConfig = {
    matches: { header: 'Matchs', format: value => Number(value).toLocaleString('fr-FR') },
    kills:   { header: 'Kills',  format: value => Number(value).toLocaleString('fr-FR') },
    heal:    { header: 'Heal',   format: value => Number(value).toLocaleString('fr-FR') },
    dpm:     { header: 'DPM',    format: value => Number(value).toFixed(1) }
};

function getLeaderboardFilename(mode, category) {
    return `/api/leaderboard?mode=${mode}&category=${category}`;
}

async function loadLeaderboard(mode, category = 'matches') {
    const tbody = document.getElementById('leaderboard-body');
    const thead = document.getElementById('leaderboard-thead');
    if (!tbody) return;

    const config = categoryConfig[category];

    // En-tête dynamique selon la catégorie
    if (thead) {
        thead.innerHTML = `
            <tr>
                <th>Rang</th>
                <th>Joueur</th>
                <th>${config.header}</th>
            </tr>
        `;
    }

    tbody.innerHTML = '<tr><td colspan="3">Chargement...</td></tr>';
    
    try {
        const filename = getLeaderboardFilename(mode, category);
        const response = await fetch(filename + '&v=' + new Date().getTime()); // Le &v=... évite le cache navigateur
        if (!response.ok) {
            throw new Error('Fichier introuvable: ' + filename);
        }
        const players = await response.json();
        
        tbody.innerHTML = ''; // clean loading message
        
        players.forEach((player, index) => {
            const row = document.createElement('tr');
            const valueKey = category === 'matches' ? 'count' : 'value';
            const displayValue = config.format(player[valueKey]);

            row.innerHTML = `
                <td>#${index + 1}</td>
                <td>
                    <div class="player-info">
                        <a href="/profile/${player.steamid}" class="player-link">
                            <img src="${player.avatar}" class="player-avatar" alt="Avatar de ${escapeHtml(player.name)}">
                            <span>${escapeHtml(player.name)}</span>
                        </a>
                    </div>
                </td>
                <td>${displayValue}</td>
            `;
            tbody.appendChild(row);
        });
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="3">Erreur lors du chargement...</td></tr>';
        console.error('Erreur:', error);
    }
}

// Sécurité basique pour éviter les caractères spéciaux dans les pseudos
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function switchLeaderboard(button, mode) {
    document.querySelectorAll('#leaderboard-mode-tabs .tab-btn').forEach(btn => btn.classList.remove('active'));
    
    button.classList.add('active');
    
    currentMode = mode;
    loadLeaderboard(currentMode, currentCategory);
}

function switchCategory(button, category) {
    document.querySelectorAll('#leaderboard-category-tabs .tab-btn').forEach(btn => btn.classList.remove('active'));

    button.classList.add('active');

    currentCategory = category;
    loadLeaderboard(currentMode, currentCategory);
}
