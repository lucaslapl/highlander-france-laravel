document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('player-search-input');
    const resultsDropdown = document.getElementById('search-results-dropdown');
    let timeout = null;

    searchInput.addEventListener('input', () => {
        clearTimeout(timeout);
        const query = searchInput.value.trim();

        // Si l'input est vide ou trop court, on cache la liste
        if (query.length < 2) {
            resultsDropdown.style.display = 'none';
            resultsDropdown.innerHTML = '';
            return;
        }

        // Système de "debounce" (on attend 300ms que l'utilisateur arrête de taper avant de lancer la requête)
        timeout = setTimeout(async () => {
            try {
                // Modifie le chemin vers ton fichier PHP si nécessaire
                const response = await fetch(`/api/search-players?q=${encodeURIComponent(query)}`);
                const players = await response.json();

                resultsDropdown.innerHTML = '';

                if (players.length === 0) {
                    resultsDropdown.innerHTML = '<div class="search-no-result">Aucun joueur trouvé</div>';
                    resultsDropdown.style.display = 'block';
                    return;
                }

                // Génération de la liste des joueurs trouvés
                players.forEach(player => {
                    const item = document.createElement('a');
                    item.href = `/profile/${player.steamid}`;
                    item.className = 'search-result-item';
                    
                    item.innerHTML = `
                        <img src="${player.avatar}" alt="Avatar">
                        <span>${escapeHtml(player.name)}</span>
                    `;
                    resultsDropdown.appendChild(item);
                });

                resultsDropdown.style.display = 'block';

            } catch (error) {
                console.error('Erreur recherche:', error);
            }
        }, 300);
    });

    // Fermer le dropdown si on clique en dehors de la barre de recherche
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !resultsDropdown.contains(e.target)) {
            resultsDropdown.style.display = 'none';
        }
    });
});

// Petite fonction de sécurité pour échapper le HTML (si tu ne l'as pas déjà dans ton fichier global)
function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return text.replace(/[&<>"']/g, m => map[m]);
}