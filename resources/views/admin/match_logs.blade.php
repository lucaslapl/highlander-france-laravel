@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #f39c12;">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> Logs des matchs joués</h2>
    <p>
        Liste des matchs avec nombre de joueurs et durée.
        <span class="admin-legend">Orange</span> = match de moins de 10 min, ou effectif incomplet ([6s] &lt; 12 joueurs, [9s] &lt; 18 joueurs).
    </p>
</div>

<div class="admin-filter">
    <input type="text" id="log-search" placeholder="Rechercher un titre ou une carte…" class="admin-search-input">
</div>

<div class="admin-table-scroll">
    <table class="admin-table" id="logsTable">
        <thead>
            <tr>
                <th>Date</th>
                <th>Carte</th>
                <th>Titre</th>
                <th class="text-center">Joueurs</th>
                <th class="text-center">Durée</th>
                <th class="text-center">Mode (BDD)</th>
                <th class="text-center">Action</th>
            </tr>
        </thead>
        <tbody>
            {!! $rows !== '' ? $rows : '<tr><td colspan="7" style="padding: 20px; text-align: center; color: #aaa; font-style: italic;">Aucun log à afficher.</td></tr>' !!}
        </tbody>
    </table>
</div>

<script>
document.getElementById("log-search").addEventListener("input", function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll("#logsTable tbody tr").forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? "" : "none";
    });
});

// Blacklister un log
document.addEventListener("click", function (event) {
    const btn = event.target.closest("#logsTable .btn-blacklist");
    if (!btn) return;
    const logId = btn.getAttribute("data-log-id");
    const logTitle = btn.getAttribute("data-log-title");

    if (!confirm(`Blacklister le log #${logId} (« ${logTitle} ») ?\nIl sera exclu des Match Stats et des statistiques.`)) {
        return;
    }

    fetch("/api/admin/blacklist", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": "{{ csrf_token() }}"
        },
        body: new URLSearchParams({ action: "add", log_id: String(logId) })
    }).then(function (response) {
        return response.ok ? response.json() : null;
    }).then(function (res) {
        if (res && res.success) {
            const row = btn.closest("tr");
            if (row) row.remove();
            const tbody = document.querySelector("#logsTable tbody");
            if (tbody && tbody.querySelectorAll("tr").length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="padding: 20px; text-align: center; color: #aaa; font-style: italic;">Aucun log à afficher.</td></tr>';
            }
        } else {
            alert(res && res.message ? res.message : "Erreur lors du blacklisting du log.");
        }
    }).catch(function () {
        alert("Erreur lors du blacklisting du log.");
    });
});

// Changer le mode de jeu (6s / 9v9)
document.addEventListener("click", function (event) {
    const btn = event.target.closest("#logsTable .btn-mode");
    if (!btn) return;
    const logId = btn.getAttribute("data-log-id");
    const targetMode = btn.getAttribute("data-mode");

    if (!confirm(`Passer le log #${logId} en mode ${String(targetMode).toUpperCase()} dans la base de données ?`)) {
        return;
    }

    fetch("/api/admin/match-mode", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": "{{ csrf_token() }}"
        },
        body: new URLSearchParams({ action: "switch_mode", log_id: String(logId), mode: String(targetMode) })
    }).then(function (response) {
        return response.ok ? response.json() : null;
    }).then(function (res) {
        alert(res && res.message ? res.message : "Réponse inattendue du serveur.");
        if (res && res.success) {
            location.reload();
        }
    }).catch(function () {
        alert("Erreur lors du changement de mode.");
    });
});
</script>
@endsection
