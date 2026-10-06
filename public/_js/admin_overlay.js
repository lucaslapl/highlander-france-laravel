/* Outil admin « Overlay match » : interversion des champs équipes Rouge /
   Bleue (nom affiché et avatar par URL) dans le formulaire de génération,
   au cas où les équipes auraient été saisies dans la mauvaise couleur. */

(function () {
    'use strict';

    const SWAPPED_FIELDS = ['name', 'avatar_url'];

    document.querySelectorAll('.js-overlay-swap').forEach((button) => {
        button.addEventListener('click', () => {
            SWAPPED_FIELDS.forEach((field) => {
                const red = document.querySelector(`[name="red_${field}"]`);
                const blue = document.querySelector(`[name="blue_${field}"]`);
                if (!red || !blue) {
                    return;
                }
                [red.value, blue.value] = [blue.value, red.value];
            });
        });
    });
}());
