@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="teams-page">
    <p class="teams-back"><a href="/equipes/{{ e($team['slug']) }}"><i class="fa-solid fa-arrow-left"></i> Retour à la fiche de l'équipe</a></p>

    <header class="teams-header">
        <h1><i class="fa-solid fa-pen"></i> Éditer {{ e($team['name']) }}</h1>
        <p>Personnalise la présentation de ton équipe, son logo et son roster. Les changements sont visibles immédiatement sur la page publique.</p>
    </header>

    @if ($errors->any())
        <div class="teams-alert teams-alert--error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ e($error) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="team-panel">
        <h2 class="team-panel__title"><i class="fa-solid fa-file-lines"></i> Présentation</h2>
        <form method="POST" action="/equipes/{{ e($team['slug']) }}/editer" class="teams-form">
            @csrf
            <label class="teams-form__label" for="slogan">Slogan (max 160 caractères)</label>
            <input id="slogan" class="teams-form__input" type="text" name="slogan" maxlength="160"
                   value="{{ old('slogan', $team['slogan'] ?? '') }}"
                   placeholder="La devise de ton équipe">

            <label class="teams-form__label" for="description">Description (Markdown)</label>
            <textarea id="description" class="teams-form__textarea" name="description" rows="10" maxlength="20000"
                      placeholder="Présente ton équipe : histoire, objectifs, ambiance…">{{ old('description', $team['description'] ?? '') }}</textarea>
            <p class="teams-form__hint">Markdown accepté : titres, listes, tableaux, liens, blocs <code>:::info|conseil|danger|combo|flank</code>.</p>

            <button type="submit" class="teams-btn"><i class="fa-solid fa-floppy-disk"></i> Enregistrer la présentation</button>
        </form>
    </section>

    <section class="team-panel">
        <h2 class="team-panel__title"><i class="fa-solid fa-image"></i> Logo</h2>
        <div class="teams-logo-row">
            @if (! empty($team['logo_url']))
                <img src="{{ e($team['logo_url']) }}" alt="Logo" class="teams-logo-preview">
            @else
                <span class="teams-logo-placeholder">{{ e($team['tag'] !== null && $team['tag'] !== '' ? $team['tag'] : mb_substr((string) $team['name'], 0, 3)) }}</span>
            @endif

            <form method="POST" action="/equipes/{{ e($team['slug']) }}/logo" enctype="multipart/form-data" class="teams-form teams-form--inline">
                @csrf
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="teams-form__file" required>
                <button type="submit" class="teams-btn"><i class="fa-solid fa-upload"></i> {{ ! empty($team['logo_url']) ? 'Remplacer' : 'Ajouter' }}</button>
            </form>

            @if (! empty($team['logo_url']))
                <form method="POST" action="/equipes/{{ e($team['slug']) }}/logo/supprimer" onsubmit="return confirm('Supprimer le logo ?');">
                    @csrf
                    <button type="submit" class="teams-btn teams-btn--danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
                </form>
            @endif
        </div>
        <p class="teams-form__hint">JPEG, PNG ou WebP, 2 Mo maximum. Format carré conseillé.</p>
    </section>

    <section class="team-panel">
        <h2 class="team-panel__title"><i class="fa-solid fa-people-group"></i> Roster ({{ count($members) }})</h2>

        @if (empty($members))
            <p class="no-data">Aucun joueur dans le roster.</p>
        @else
            <ul class="teams-edit-roster">
                @foreach ($members as $member)
                    <li class="teams-edit-member">
                        <img loading="lazy" decoding="async" src="{{ e($member['avatar_url']) }}" alt="" class="teams-edit-member__avatar" width="40" height="40">

                        <div class="teams-edit-member__id">
                            <span class="teams-edit-member__name">
                                {{ e($member['final_name']) }}
                                @if ($member['is_leader'])
                                    <i class="fa-solid fa-star team-roster__leader" title="Leader"></i>
                                @endif
                            </span>
                            <span class="teams-edit-member__steam">
                                @include('partials.flag', ['country' => $member['country'] ?? null, 'label' => (string) ($member['country'] ?? '')])
                                <code>{{ e($member['steamid64']) }}</code>
                            </span>
                        </div>

                        <form method="POST" action="/equipes/{{ e($team['slug']) }}/membres/{{ (int) $member['id'] }}/modifier" class="teams-edit-member__form">
                            @csrf
                            <select name="class" class="teams-form__select">
                                <option value="">— Classe —</option>
                                @foreach ($classes as $classKey => $classLabel)
                                    <option value="{{ $classKey }}" @selected((string) ($member['class'] ?? '') === $classKey)>{{ $classLabel }}</option>
                                @endforeach
                            </select>
                            <select name="status" class="teams-form__select">
                                <option value="starter" @selected(($member['status'] ?? 'starter') === 'starter')>Titulaire</option>
                                <option value="backup" @selected(($member['status'] ?? 'starter') === 'backup')>Remplaçant</option>
                            </select>
                            <button type="submit" class="teams-btn teams-btn--small" title="Enregistrer"><i class="fa-solid fa-floppy-disk"></i></button>
                        </form>

                        <form method="POST" action="/equipes/{{ e($team['slug']) }}/membres/{{ (int) $member['id'] }}/retirer" onsubmit="return confirm('Retirer ce joueur du roster ?');">
                            @csrf
                            <button type="submit" class="teams-btn teams-btn--danger teams-btn--small" title="Retirer"><i class="fa-solid fa-user-xmark"></i></button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="team-panel">
        <h2 class="team-panel__title"><i class="fa-solid fa-user-plus"></i> Ajouter un joueur</h2>
        <form method="POST" action="/equipes/{{ e($team['slug']) }}/membres/ajouter" class="teams-form">
            @csrf
            <div class="teams-form__grid">
                <div>
                    <label class="teams-form__label" for="steamid64">SteamID64</label>
                    <input id="steamid64" class="teams-form__input" type="text" name="steamid64" pattern="\d{17}" maxlength="17" placeholder="7656119XXXXXXXXXX" required>
                </div>
                <div>
                    <label class="teams-form__label" for="member_class">Classe</label>
                    <select id="member_class" name="class" class="teams-form__select">
                        <option value="">—</option>
                        @foreach ($classes as $classKey => $classLabel)
                            <option value="{{ $classKey }}">{{ $classLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="teams-form__label" for="member_status">Statut</label>
                    <select id="member_status" name="status" class="teams-form__select">
                        <option value="starter">Titulaire</option>
                        <option value="backup">Remplaçant</option>
                    </select>
                </div>
            </div>
            <p class="teams-form__hint">Le joueur doit avoir un compte Steam. S'il est inscrit sur le site, son pseudo et son avatar seront repris automatiquement.</p>
            <button type="submit" class="teams-btn"><i class="fa-solid fa-user-plus"></i> Ajouter au roster</button>
        </form>
    </section>
</div>
@endsection