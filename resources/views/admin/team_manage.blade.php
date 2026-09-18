@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-back admin-back--split">
    <a href="/admin/equipes"><i class="fa-solid fa-arrow-left"></i> Retour à la liste</a>
    <a href="/equipes/{{ e($team['slug']) }}" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> Voir la page publique</a>
</div>

<div class="admin-header" style="--accent:#2ec4b6;">
    <h2>
        @if (!empty($team['logo_url']))
            <img src="{{ $team['logo_url'] }}" alt="" style="width:44px; height:44px; object-fit:contain; vertical-align:middle; border-radius:6px; margin-right:8px;">
        @endif
        <i class="fa-solid fa-users-between-lines"></i> {{ e($team['name']) }}
    </h2>
    <p>
        Équipe ETF2L #{{ (int) $team['etf2l_team_id'] }}
        — <a href="https://etf2l.org/teams/{{ (int) $team['etf2l_team_id'] }}/" target="_blank" rel="noopener" style="color:#fbb7fb;">page ETF2L</a>
        @if ((int) $team['is_active'])
            — <span style="color:#00bc8c;"><i class="fa-solid fa-circle-check"></i> Active sur le site</span>
        @else
            — <span style="color:#f39c12;"><i class="fa-solid fa-pen-to-square"></i> Brouillon (non visible publiquement)</span>
        @endif
    </p>
    <p style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
        <form action="/admin/equipes/{{ (int) $team['id'] }}/toggle" method="POST" style="display:inline;">@csrf
            <button type="submit" class="admin-btn {{ (int) $team['is_active'] ? 'admin-btn--danger' : 'admin-btn--success' }}">
                <i class="fa-solid {{ (int) $team['is_active'] ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                {{ (int) $team['is_active'] ? 'Masquer' : 'Activer sur le site' }}
            </button>
        </form>
        <form action="/admin/equipes/{{ (int) $team['id'] }}/resync" method="POST" style="display:inline;">@csrf
            <button type="submit" class="admin-btn"><i class="fa-solid fa-rotate"></i> Re-synchroniser le roster ETF2L</button>
        </form>
        <form action="/admin/equipes/{{ (int) $team['id'] }}/delete" method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette équipe et son roster ?');">@csrf
            <button type="submit" class="admin-btn admin-btn--danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
        </form>
    </p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-pen-to-square"></i> Informations & présentation</h3>
<form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/update" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
        <div class="form-group">
            <label class="admin-form-label">Nom de l'équipe</label>
            <input class="form-control" name="name" value="{{ old('name', $team['name']) }}" maxlength="255" required>
        </div>
        <div class="form-group">
            <label class="admin-form-label">Tag</label>
            <input class="form-control" name="tag" value="{{ old('tag', $team['tag'] ?? '') }}" maxlength="64">
        </div>
        <div class="form-group">
            <label class="admin-form-label">Pays (code ISO)</label>
            <input class="form-control" name="country" value="{{ old('country', $team['country'] ?? '') }}" maxlength="64" placeholder="fr">
        </div>
        <div class="form-group">
            <label class="admin-form-label">Division</label>
            <select class="form-control" name="division">
                <option value="">— Non définie —</option>
                @foreach ($divisions as $key => $label)
                    <option value="{{ $key }}" @selected(old('division', $team['division'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="form-group">
        <label class="admin-form-label">Slogan (affiché sous le nom, max 160 caractères)</label>
        <input class="form-control" name="slogan" value="{{ old('slogan', $team['slogan'] ?? '') }}" maxlength="160">
    </div>
    <div class="form-group">
        <label class="admin-form-label">Description (Markdown)</label>
        <textarea class="form-control" name="description" rows="8" maxlength="20000">{{ old('description', $team['description'] ?? '') }}</textarea>
        <span class="form-hint">Markdown étendu (titres, listes, tableaux, blocs <code>:::info|conseil|danger|combo|flank</code>). Événementiel et lien vers la page ETF2L conseillés.</span>
    </div>
    <div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
    </div>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-image"></i> Logo</h3>
<div class="admin-card">
    <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap;">
        @if (!empty($team['logo_url']))
            <div>
                <img src="{{ $team['logo_url'] }}" alt="Logo" style="max-width:140px; max-height:140px; object-fit:contain; border-radius:8px; border:1px solid #2c3e50;">
            </div>
        @else
            <p style="color:#aaa; font-style:italic; margin:0;">Aucun logo. Les équipes sans logo utilisent leur tag comme fallback.</p>
        @endif
        <form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/logo" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px;">
            @csrf
            <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="form-control" required>
            <button type="submit" class="admin-btn"><i class="fa-solid fa-upload"></i> {{ !empty($team['logo_url']) ? 'Remplacer le logo' : 'Ajouter un logo' }}</button>
        </form>
        @if (!empty($team['logo_url']))
            <form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/logo/delete" onsubmit="return confirm('Supprimer le logo ?');">
                @csrf
                <button type="submit" class="admin-btn admin-btn--danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
            </form>
        @endif
    </div>
    <span class="form-hint">JPEG, PNG ou WebP, 2 Mo maximum. Le leader peut aussi modifier le logo depuis la page d'édition de l'équipe.</span>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-people-group"></i> Roster ({{ count($members) }})</h3>

@if (empty($members))
    <p class="admin-empty">Le roster est vide. Ajoutez un joueur ci-dessous ou lancez une synchronisation ETF2L.</p>
@else
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Joueur</th>
                    <th>SteamID</th>
                    <th>Profil site</th>
                    <th>Classe</th>
                    <th>Statut</th>
                    <th>Rôle</th>
                    <th>Source</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($members as $member)
                <tr>
                    <td style="white-space:nowrap;">
                        <img src="{{ $member['avatar_url'] }}" alt="" style="width:32px; height:32px; object-fit:cover; border-radius:50%; vertical-align:middle; margin-right:8px;">
                        {{ e($member['final_name']) }}
                        @if ($member['is_leader'])
                            <span title="Leader" style="color:#fbb7fb;"><i class="fa-solid fa-star"></i></span>
                        @endif
                        @if (!empty($member['country']))
                            <img src="/_img/flags/{{ e($member['country']) }}.gif" alt="{{ strtoupper(e($member['country'])) }}" class="tech-member__flag">
                        @endif
                    </td>
                    <td><code>{{ e($member['steamid64']) }}</code><br><code>{{ e((string) $member['steamid3']) }}</code></td>
                    <td>
                        @if ($member['exists_on_site'])
                            <a href="/admin/manage-player/{{ e($member['steamid64']) }}" target="_blank" rel="noopener"><i class="fa-solid fa-user-gear"></i> Gérer</a>
                        @else
                            <span style="color:#777;">hors site</span>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/members/{{ (int) $member['id'] }}/update">
                            @csrf
                            <select class="form-control" name="class" style="min-width:130px;">
                                <option value="">—</option>
                                @foreach ($classes as $classKey => $classLabel)
                                    <option value="{{ $classKey }}" @selected((string) ($member['class'] ?? '') === $classKey)>{{ $classLabel }}</option>
                                @endforeach
                            </select>
                            <select class="form-control" name="status" style="min-width:120px; margin-top:6px;">
                                <option value="starter" @selected(($member['status'] ?? 'starter') === 'starter')>Titulaire</option>
                                <option value="backup" @selected(($member['status'] ?? 'starter') === 'backup')>Remplaçant</option>
                            </select>
                            <button class="btn-icon" title="Enregistrer classe / statut"><i class="fa-solid fa-floppy-disk"></i></button>
                        </form>
                    </td>
                    <td>{{ e($member['status_label']) }}</td>
                    <td>
                        @if ($member['is_leader'])
                            <span class="status-pill" style="background:rgba(251,183,251,.15); color:#fbb7fb;">Leader</span>
                            <form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/members/{{ (int) $member['id'] }}/leader" style="display:inline;">
                                @csrf
                                <button class="btn-icon" title="Retirer le rôle leader"><i class="fa-solid fa-star-half-stroke"></i></button>
                            </form>
                        @else
                            <form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/members/{{ (int) $member['id'] }}/leader" style="display:inline;">
                                @csrf
                                <button class="btn-icon" title="Promouvoir leader"><i class="fa-regular fa-star"></i></button>
                            </form>
                        @endif
                    </td>
                    <td>
                        @if ($member['source'] === 'etf2l')
                            <span style="color:#5cb85c;" title="Importé depuis l'API ETF2L"><i class="fa-solid fa-tower-broadcast"></i> ETF2L</span>
                        @else
                            <span style="color:#3498db;" title="Ajouté à la main"><i class="fa-solid fa-pen"></i> Manuel</span>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/members/{{ (int) $member['id'] }}/remove" onsubmit="return confirm('Retirer {{ addslashes((string) $member['final_name']) }} du roster ?');">
                            @csrf
                            <button class="btn-icon admin-btn--danger" title="Retirer du roster"><i class="fa-solid fa-user-xmark"></i></button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<h3 class="admin-section-title"><i class="fa-solid fa-user-plus"></i> Ajouter un membre au roster</h3>
<form method="POST" action="/admin/equipes/{{ (int) $team['id'] }}/members/add" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
        <div class="form-group">
            <label class="admin-form-label">SteamID64</label>
            <input class="form-control" name="steamid64" placeholder="7656119XXXXXXXXXX" pattern="\d{17}" maxlength="17" required>
        </div>
        <div class="form-group">
            <label class="admin-form-label">Classe (optionnel)</label>
            <select class="form-control" name="class">
                <option value="">—</option>
                @foreach ($classes as $classKey => $classLabel)
                    <option value="{{ $classKey }}">{{ $classLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label class="admin-form-label">Statut</label>
            <select class="form-control" name="status">
                <option value="starter">Titulaire</option>
                <option value="backup">Remplaçant</option>
            </select>
        </div>
        <div class="form-group">
            <label class="admin-form-label"><input type="checkbox" name="is_leader" value="1"> Leader</label>
            <span class="form-hint">Le leader pourra éditer la page équipe (description, logo, roster).</span>
        </div>
    </div>
    <div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-user-plus"></i> Ajouter au roster</button>
    </div>
</form>
@endsection