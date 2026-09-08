(function () {
    'use strict';

    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    document.querySelectorAll('.liga-log-remove').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var ok = window.confirm('Retirer ce log de la liste des logs officiels ?');
            if (!ok) return;
            var form = this.closest('form');
            form.submit();
        });
    });

    var teamSelect = document.getElementById('team-log-team');
    var categorySelect = document.getElementById('team-log-category');
    if (teamSelect) {
        teamSelect.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            if (opt && opt.dataset !== undefined && opt.dataset.category) {
                categorySelect.value = opt.dataset.category;
            }
        });
    }
})();
