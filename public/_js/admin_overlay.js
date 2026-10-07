/* Outils admin « Overlay Logs » et « Overlay Scores » : application en un
   clic d'une équipe mémorisée (nom affiché + URL d'avatar) aux champs du
   formulaire, depuis les vignettes « équipes déjà castées » de chaque carte
   équipe (partial admin/partials/avatar_memory). */

(function () {
    'use strict';

    document.querySelectorAll('.overlay-avatar-memory__tile').forEach((tile) => {
        tile.addEventListener('click', () => {
            const group = tile.closest('.overlay-avatar-memory');
            const team = group ? group.dataset.team : null;
            if (! team) {
                return;
            }

            const name = document.querySelector(`[name="${team}_name"]`);
            const avatarUrl = document.querySelector(`[name="${team}_avatar_url"]`);
            if (name) {
                name.value = tile.dataset.name || '';
            }
            if (avatarUrl) {
                avatarUrl.value = tile.dataset.url || '';
            }
        });
    });
}());
