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
            {{ $rows !== '' ? $rows : '<tr><td colspan="7" style="padding: 20px; text-align: center; color: #aaa; font-style: italic;">Aucun log à afficher.</td></tr>' }}
        </tbody>
    </table>
</div>

<script>
$("#log-search").on("input", function () {
    const q = this.value.toLowerCase();
    $("#logsTable tbody tr").each(function () {
        $(this).toggle($(this).text().toLowerCase().includes(q));
    });
});

// Blacklister un log
$(document).on("click", ".btn-blacklist", function () {
    const btn = $(this);
    const logId = btn.data("log-id");
    const logTitle = btn.data("log-title");

    if (!confirm(`Blacklister le log #${logId} (« ${logTitle} ») ?\nIl sera exclu des Match Stats et des statistiques.`)) {
        return;
    }

    $.ajax({
        type: "POST",
        url: "/api/admin/blacklist",
        data: { action: "add", log_id: logId },
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": "{{ csrf_token() }}"
        },
        dataType: "json"
    }).done(function (res) {
        if (res.success) {
            btn.closest("tr").remove();
            if ($("#logsTable tbody tr").length === 0) {
                $("#logsTable tbody").html('<tr><td colspan="7" style="padding: 20px; text-align: center; color: #aaa; font-style: italic;">Aucun log à afficher.</td></tr>');
            }
        } else {
            alert(res.message);
        }
    }).fail(function () {
        alert("Erreur lors du blacklisting du log.");
    });
});

// Changer le mode de jeu (6s / 9v9)
$(document).on("click", ".btn-mode", function () {
    const btn = $(this);
    const logId = btn.data("log-id");
    const targetMode = btn.data("mode");

    if (!confirm(`Passer le log #${logId} en mode ${targetMode.toUpperCase()} dans la base de données ?`)) {
        return;
    }

    $.ajax({
        type: "POST",
        url: "/api/admin/match-mode",
        data: { action: "switch_mode", log_id: logId, mode: targetMode },
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": "{{ csrf_token() }}"
        },
        dataType: "json"
    }).done(function (res) {
        alert(res.message);
        if (res.success) {
            location.reload();
        }
    }).fail(function () {
        alert("Erreur lors du changement de mode.");
    });
});
</script>
@endsection
