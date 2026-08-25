(function () {
    if (typeof Chart === 'undefined' || !window.__dashboardData) return;

    const data = window.__dashboardData;

    Chart.defaults.color = '#aaa';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';
    Chart.defaults.font.family = 'Montserrat, sans-serif';

    function pad(n) { return String(n).padStart(2, '0'); }
    function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    // Rebin les agrégats quotidiens [{d:'YYYY-MM-DD', nb}] selon la période demandée
    function rebin(rows, period, rangeDays) {
        const map = {};
        (rows || []).forEach(function (r) { map[r.d] = parseInt(r.nb, 10) || 0; });

        const end = new Date(); end.setHours(0, 0, 0, 0);
        const start = new Date(end); start.setDate(start.getDate() - (rangeDays - 1));

        function bucketKey(d) {
            if (period === 'day') return 'd:' + fmtDate(d);
            if (period === 'week') {
                const monday = new Date(d);
                monday.setDate(d.getDate() - ((d.getDay() + 6) % 7)); // semaine = lundi
                return 'w:' + fmtDate(monday);
            }
            return 'm:' + d.getFullYear() + '-' + pad(d.getMonth() + 1);
        }

        function bucketLabel(d) {
            if (period === 'day') return pad(d.getDate()) + '/' + pad(d.getMonth() + 1);
            if (period === 'week') {
                const monday = new Date(d);
                monday.setDate(d.getDate() - ((d.getDay() + 6) % 7));
                return 'Sem. ' + pad(monday.getDate()) + '/' + pad(monday.getMonth() + 1);
            }
            const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
            return months[d.getMonth()] + ' ' + String(d.getFullYear()).slice(2);
        }

        const out = [];
        let cur = null;
        for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            const key = bucketKey(d);
            if (!cur || cur.key !== key) {
                if (cur) out.push(cur);
                cur = { key: key, label: bucketLabel(d), count: 0 };
            }
            cur.count += map[fmtDate(d)] || 0;
        }
        if (cur) out.push(cur);
        return out;
    }

    // ---------- Chart 1 : Inscriptions ----------
    const regBuckets = {
        week: rebin(data.registrations, 'week', 120),
        month: rebin(data.registrations, 'month', 365)
    };
    const regChart = new Chart(document.getElementById('chart-registrations'), {
        type: 'line',
        data: {
            labels: regBuckets.month.map(function (b) { return b.label; }),
            datasets: [{
                label: 'Inscriptions',
                data: regBuckets.month.map(function (b) { return b.count; }),
                borderColor: '#ff4444',
                backgroundColor: 'rgba(255, 68, 68, 0.15)',
                fill: true,
                tension: 0.35,
                pointRadius: 3,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    // ---------- Chart 2 : Matchs joués ----------
    const matchBuckets = {
        day: rebin(data.matchesPerDay, 'day', 90),
        week: rebin(data.matchesPerDay, 'week', 120),
        month: rebin(data.matchesPerDay, 'month', 365)
    };
    const matchChart = new Chart(document.getElementById('chart-matches'), {
        type: 'bar',
        data: {
            labels: matchBuckets.week.map(function (b) { return b.label; }),
            datasets: [{
                label: 'Matchs joués',
                data: matchBuckets.week.map(function (b) { return b.count; }),
                backgroundColor: '#3498db',
                borderRadius: 3,
                maxBarThickness: 40
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    // ---------- Chart 3 : Donut 6s / 9v9 ----------
    const modes = data.modes || {};
    const modeLabels = [];
    const modeValues = [];
    const modeColors = [];
    if (modes['9v9']) { modeLabels.push('9v9'); modeValues.push(modes['9v9']); modeColors.push('#ff4444'); }
    if (modes['6s'])  { modeLabels.push('6s');  modeValues.push(modes['6s']);  modeColors.push('#3498db'); }

    new Chart(document.getElementById('chart-modes'), {
        type: 'doughnut',
        data: {
            labels: modeLabels,
            datasets: [{
                data: modeValues,
                backgroundColor: modeColors,
                borderColor: '#141419',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 16, usePointStyle: true, pointStyle: 'circle' }
                }
            }
        }
    });

    // ---------- Toggles Semaine/Mois & Jour/Semaine/Mois ----------
    document.querySelectorAll('.chart-toggles').forEach(function (toggleGroup) {
        const target = toggleGroup.getAttribute('data-target');
        toggleGroup.querySelectorAll('.chart-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                toggleGroup.querySelectorAll('.chart-toggle').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                const period = btn.getAttribute('data-period');
                let chart, buckets;
                if (target === 'registrations') {
                    chart = regChart;
                    buckets = regBuckets[period];
                } else {
                    chart = matchChart;
                    buckets = matchBuckets[period];
                }
                chart.data.labels = buckets.map(function (b) { return b.label; });
                chart.data.datasets[0].data = buckets.map(function (b) { return b.count; });
                chart.update();
            });
        });
    });
})();