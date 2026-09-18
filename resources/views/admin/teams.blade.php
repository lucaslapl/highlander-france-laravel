@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-header" style="--accent:#2ec4b6;">
    <h2><i class="fa-solid fa-users-between-lines"></i> Équipes FR</h2>
    <p>Équipes françaises mise en avant sur la sidebar d'accueil et les pages /equipes. Le roster est importé de l'API ETF2L puis complété/validé ici.</p>
</div>

@if (empty($teams))
    <p style="color:#aaa; font-style:italic;">Aucune équipe gérée pour le moment. Importez votre première équipe ci-dessus.</p>
@endif

<h3 class="admin-section-title"><i class="fa-solid fa-tower-broadcast"></i> Importer une équipe ETF2L</h3>
<form method="POST" action="/admin/equipes/store" class="admin-form-stack" id="team-import-form">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
        <div style="flex:1; min-width:280px; position:relative;">
            <label class="admin-form-label">Équipe ETF2L (nom ou tag)</label>
            <input type="text" id="team-search-input" class="form-control" placeholder="Ex : HomeFrag, Full Disclosure…" autocomplete="off">
            <div id="team-search-results" class="search-dropdown" style="display:none;"></div>
        </div>
        <div style="min-width:180px;">
            <label class="admin-form-label">Id ETF2L (rempli par la recherche)</label>
            <input type="number" min="1" max="9999999" name="etf2l_team_id" id="team-search-id" class="form-control" placeholder="Ex : 332">
        </div>
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-download"></i> Importer
        </button>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Les équipes non encore présentes en base sont récupérées à la volée via l'API ETF2L. La division est proposée automatiquement d'après les compétitions récentes et restera modifiable ci-dessous.
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-shield-halved"></i> Équipes gérées ({{ count($teams) }})</h3>
<div class="admin-table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Équipe</th>
                <th>Tag</th>
                <th>Pays</th>
                <th>Division</th>
                <th>Roster</th>
                <th>Leaders</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($teams as $team)
            <tr>
                <td>
                    <a href="/admin/equipes/{{ (int) $team['id'] }}">
                        @if (!empty($team['logo_url']))
                            <img src="{{ $team['logo_url'] }}" alt="" style="width:28px; height:28px; object-fit:contain; vertical-align:middle; border-radius:4px;">
                        @endif
                        {{ e($team['name']) }}
                    </a>
                </td>
                <td><code>{{ e($team['tag'] ?? '—') }}</code></td>
                <td>
                    @if (!empty($team['country']))
                        <img src="/_img/flags/{{ e($team['country']) }}.gif" alt="{{ strtoupper(e($team['country'])) }}" class="tech-member__flag">
                    @else
                        —
                    @endif
                </td>
                <td>{{ e($divisions[$team['division']] ?? '—') }}</td>
                <td>{{ (int) $team['member_count'] }}</td>
                <td>{{ (int) $team['leader_count'] }}</td>
                <td>
                    @if ((int) $team['is_active'])
                        <span class="status-pill" style="background:rgba(0,188,140,.15); color:#00bc8c;">Active</span>
                    @else
                        <span class="status-pill" style="background:rgba(255,244,68,.12); color:#f39c12;">Brouillon</span>
                    @endif
                </td>
                <td>
                    <a class="btn-icon" href="/admin/equipes/{{ (int) $team['id'] }}" title="Gérer"><i class="fa-solid fa-gears"></i></a>
                    <a class="btn-icon" href="/equipes/{{ e($team['slug']) }}" title="Voir la page publique" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i></a>
                    <form action="/admin/equipes/{{ (int) $team['id'] }}/toggle" method="POST" style="display:inline;">@csrf<button class="btn-icon" title="Activer / masquer"><i class="fa-solid fa-eye-slash"></i></button></form>
                    <form action="/admin/equipes/{{ (int) $team['id'] }}/delete" method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette équipe et son roster ?');">@csrf<button class="btn-icon" title="Supprimer"><i class="fa-solid fa-trash"></i></button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@push('scripts')
<script>
(function () {
    const input = document.getElementById('team-search-input');
    const results = document.getElementById('team-search-results');
    const direct = document.getElementById('team-search-id');
    const form = document.getElementById('team-import-form');
    let timer = null;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function () {
            fetch('/admin/equipes/search?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(items => {
                    results.innerHTML = '';
                    if (!items.length) {
                        results.innerHTML = '<div class="search-dropdown__item">Aucune équipe locale trouvée. Saisissez directement l\'id ETF2L si l\'équipe n\'est pas encore en base.</div>';
                    }
                    items.forEach(item => {
                        const row = document.createElement('div');
                        row.className = 'search-dropdown__item';
                        row.textContent = item.name + (item.tag ? ' [' + item.tag + ']' : '') + (item.country ? ' — ' + item.country : '') + ' (#' + item.id + ')';
                        row.addEventListener('click', function () {
                            input.value = item.name;
                            direct.value = item.id;
                            results.style.display = 'none';
                        });
                        results.appendChild(row);
                    });
                    results.style.display = 'block';
                });
        }, 200);
    });
    document.addEventListener('click', function (e) {
        if (!results.contains(e.target)) { results.style.display = 'none'; }
    });
    form.addEventListener('submit', function (e) {
        if (!direct.value) {
            e.preventDefault();
            alert('Choisissez une équipe : sélectionnez un résultat de recherche ou saisissez un id ETF2L.');
        }
    });
})();
</script>
@endpush

<style>
.search-dropdown { position:absolute; z-index:50; width:100%; max-width:560px; background:#1b2333; border:1px solid #2c3e50; border-radius:6px; max-height:280px; overflow:auto; }
.search-dropdown__item { padding:8px 10px; cursor:pointer; font-size:13px; color:#ddd; }
.search-dropdown__item:hover { background:#2c3e50; }
</style>
@endsection