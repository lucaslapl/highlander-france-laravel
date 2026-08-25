@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@push('styles')
@once
<link rel="stylesheet" href="/_css/admin.css">
@endonce
@endpush
<script>
window.__dashboardData = {!! json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
</script>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="/_js/admin_charts.js"></script>
<script src="/_js/admin_player_search.js"></script>
@endpush

@section('content')

<div class="admin-header" style="--accent: #ff4444;">
    <h2><i class="fa-solid fa-screwdriver-wrench"></i> Panel d'Administration</h2>
    <p>Bienvenue dans l'espace de gestion de la communauté Highlander France.</p>
</div>

<div class="admin-stats-grid">
    <div class="admin-stat-card" style="--accent: #ff4444;">
        <span>Nombre de joueurs dans la base de données</span>
        <h3>{{ (int)$dashboard['totalPlayers'] }}</h3>
    </div>
    <div class="admin-stat-card" style="--accent: #3498db;">
        <span>Joueurs enregistrés (web)</span>
        <h3>{{ (int)$dashboard['totalRegistered'] }}</h3>
    </div>
    <div class="admin-stat-card" style="--accent: #00bc8c;">
        <span>Membres du staff</span>
        <h3>{{ (int)$dashboard['totalStaff'] }}</h3>
    </div>
</div>

<div class="dashboard-charts">
    <h3 class="admin-section-title">
        <i class="fa-solid fa-chart-line"></i> Statistiques
    </h3>

    <div class="charts-grid">

        <div class="chart-card">
            <div class="chart-card__header">
                <h4 class="chart-card__title">
                    <i class="fa-solid fa-user-plus"></i> Inscriptions
                </h4>
                <div class="chart-toggles" data-target="registrations">
                    <button type="button" class="chart-toggle" data-period="week">Semaine</button>
                    <button type="button" class="chart-toggle active" data-period="month">Mois</button>
                </div>
            </div>
            <div class="chart-card__body">
                <canvas id="chart-registrations"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card__header">
                <h4 class="chart-card__title">
                    <i class="fa-solid fa-clock-rotate-left"></i> Matchs joués
                </h4>
                <div class="chart-toggles" data-target="matches">
                    <button type="button" class="chart-toggle" data-period="day">Jour</button>
                    <button type="button" class="chart-toggle active" data-period="week">Semaine</button>
                    <button type="button" class="chart-toggle" data-period="month">Mois</button>
                </div>
            </div>
            <div class="chart-card__body">
                <canvas id="chart-matches"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card__header">
                <h4 class="chart-card__title">
                    <i class="fa-solid fa-scale-balanced"></i> Répartition 6s / 9v9
                </h4>
            </div>
            <div class="chart-card__body chart-card__body--tall">
                <canvas id="chart-modes"></canvas>
            </div>
        </div>

    </div>
</div>

<div class="admin-content-layout">

    <section class="admin-manipulations">
        <h3 class="admin-section-title">Actions disponibles</h3>

        <div class="admin-cards-grid">

            <div class="admin-action-card" style="--accent: #ff4444;">
                <div>
                    <h4 class="admin-action-card__title"><i class="fa-solid fa-users-gear"></i> Modération des joueurs</h4>
                    <p class="admin-action-card__desc">Rechercher un profil, attribuer/retirer des rôles, changer le pseudo ou nationalité d'un joueur (ou réinitialiser la restriction associée).</p>
                </div>
                <div class="search-container">
                    <input type="text" id="player-search-input" placeholder="Rechercher un joueur..." autocomplete="off">
                    <div id="search-results-dropdown" class="search-dropdown" style="display: none;"></div>
                </div>
            </div>

            <div class="admin-action-card" style="--accent: #00bc8c;">
                <h4 class="admin-action-card__title"><i class="fa-solid fa-user-shield"></i> L'équipe complète</h4>
                <p class="admin-action-card__desc">Liste complète des utilisateurs possédant un rôle staff pour vérifier les permissions globales.</p>
                <a href="/admin/list-staff" class="admin-link-btn">Voir l'équipe</a>
            </div>

            <div class="admin-action-card" style="--accent: #f39c12;">
                <h4 class="admin-action-card__title"><i class="fa-solid fa-rotate"></i> Tâches CRON</h4>
                <p class="admin-action-card__desc">NE PAS UTILISER SAUF URGENCE OU SANS Y AVOIR ÉTÉ INVITÉ</p>
                <a href="/admin/run-cron-manual" class="admin-link-btn">Panel CRON</a>
            </div>

            <div class="admin-action-card" style="--accent: #3498db;">
                <h4 class="admin-action-card__title"><i class="fa-solid fa-database"></i> Logs du site</h4>
                <p class="admin-action-card__desc">(Indisponible pour le moment)</p>
                <a href="/admin/view-logs" class="admin-link-btn">Ouvrir l'inspecteur log</a>
            </div>

            <div class="admin-action-card" style="--accent: #f39c12;">
                <h4 class="admin-action-card__title"><i class="fa-solid fa-clock-rotate-left"></i> Logs des matchs joués</h4>
                <p class="admin-action-card__desc">Liste des matchs joués avec nombre de joueurs et durée, avec alertes orange (match court, effectif incomplet).</p>
                <a href="/admin/match-logs" class="admin-link-btn">Voir les logs</a>
            </div>

            <div class="admin-action-card" style="--accent: #f35f5f;">
                <h4 class="admin-action-card__title"><i class="fa-solid fa-ban"></i> Logs blacklistés</h4>
                <p class="admin-action-card__desc">Exclure des logs logs.tf des stats et de la page Match Stats, avec motif et traçabilité.</p>
                <a href="/admin/manage-blacklist" class="admin-link-btn">Gérer la blacklist</a>
            </div>
        </div>

        <div class="admin-api-status">
            <div class="api-status-header">
                <h3>
                    <i class="fa-solid fa-tower-broadcast"></i> Statut des API
                </h3>
                <a href="/admin/dashboard?refresh_apis=1" class="admin-btn">
                    <i class="fa-solid fa-rotate"></i> Vérifier maintenant
                </a>
            </div>

            @if (empty($apiStatuses))
                <p style="color: #666; font-size: 14px; margin: 0;">Impossible de récupérer le statut des API.</p>
            @else
                @php
    $statusColors = ['ok' => '#00bc8c', 'slow' => '#f39c12', 'down' => '#ff4444', 'error' => '#ff4444'];
                $statusLabels = ['ok' => 'Opérationnel', 'slow' => 'Lent', 'down' => 'Indisponible', 'error' => 'Erreur'];
                
@endphp
                <div class="api-status-grid">
                    @foreach ($apiStatuses as $api)
                        @php
    $color = $statusColors[$api['status']] ?? '#ff4444';
                        $label = $statusLabels[$api['status']] ?? 'Inconnu';
                        
@endphp
                        <div class="api-status-card" style="--accent: {{ $color }};">
                            <div class="api-status-card__header">
                                <strong class="api-status-card__name">
                                    <i class="{!! e($api['icon']) !!}"></i>
                                    {!! e($api['api']) !!}
                                </strong>
                                <span class="status-pill">{{ $label }}</span>
                            </div>

                            <div class="api-status-card__meta">
                                <span><i class="fa-solid fa-gauge-high" style="width: 18px;"></i> Latence : <strong>{{ $api['latency_ms'] !== null ? $api['latency_ms'] . ' ms' : '—' }}</strong></span>
                                <span><i class="fa-solid fa-code" style="width: 18px;"></i> HTTP : <strong>{{ $api['http_code'] ?: '—' }}</strong></span>
<span style="color: {!! $api["status"] === "ok" ? "#aaa" : $color !!};">{!! e($api["message"]) !!}</span>

                                @if (!empty($api['last_sync']))
                                    @php
    $ls = $api['last_sync'];
                                    $ago = '';
                                    $ts = (int)($ls['ts'] ?? 0);
                                    if ($ts > 0) {
                                        $diff = time() - $ts;
                                        if ($diff < 60) {
                                            $ago = 'à l\'instant';
                                        } elseif ($diff < 3600) {
                                            $ago = 'il y a ' . floor($diff / 60) . ' min';
                                        } elseif ($diff < 86400) {
                                            $ago = 'il y a ' . floor($diff / 3600) . ' h';
                                        } else {
                                            $ago = 'il y a ' . floor($diff / 86400) . ' j';
                                        }
                                    }
                                    
@endphp
                                    <span class="api-divider" style="color: {{ $ls['status'] === 'success' ? '#00bc8c' : '#ff4444' }};">
                                        <i class="fa-solid {{ $ls['status'] === 'success' ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
                                        Dernière synchro : {!! e($ls['message']) !!}{{ $ago !== '' ? ' · ' . $ago : '' }}
                                    </span>
                                @else
                                    <span class="api-divider" style="color: #666;">
                                        <i class="fa-solid fa-circle-question"></i> Aucune exécution de script enregistrée
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <aside class="admin-sidebar">
        <h3 class="admin-sidebar__title">Dernières inscriptions</h3>

        @if (empty($dashboard['recentUsers']))
            <p class="admin-sidebar__empty">Aucun utilisateur trouvé.</p>
        @else
            <ul class="admin-sidebar__list">
                @foreach ($dashboard['recentUsers'] as $user)
                    @php
    $name = !empty($user['display_name']) ? $user['display_name'] : $user['name'];
                    $date = date('d/m à H:i', strtotime((string)$user['created_at']));
                    
@endphp
                    <li class="admin-sidebar__item">
                        <div style="display: flex; flex-direction: column;">
                            <a href="/profile/{!! e($user['steamid64']) !!}" target="_blank" class="admin-sidebar__item-link">
                                {!! e($name) !!}
                            </a>
                            <span class="admin-sidebar__item-meta">{!! e($date) !!}</span>
                        </div>
                        <a href="/admin/manage-player/{!! e($user['steamid64']) !!}" class="admin-sidebar__manage" title="Gérer cet utilisateur">
                            <i class="fa-solid fa-user-pen"></i>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="admin-card admin-card--alt">
            <h3 class="admin-sidebar__title" style="--accent: #e74c3c;">
                <i class="fa-solid fa-code font-awesome-icon"></i> Équipe Technique
                <span class="status-pill">
                    {{ count($techTeam) }}
                </span>
            </h3>

            @if (empty($techTeam))
                <p class="admin-sidebar__hint" style="color: #aaa; font-style: italic;">Aucun administrateur configuré (Bizarre !).</p>
            @else
                <div class="admin-sidebar__stack">
                    <p class="admin-sidebar__hint">Utilisateurs ayant accès à ce panel.</p>
                    @foreach ($techTeam as $admin)
                        @php
    $steamid64Link = \App\Services\SteamId::toSteamId64($admin['steamid']); 
@endphp
                        <div class="tech-member">
                            <div class="tech-member__identity">
                                @if (!empty($admin['country']))
                                    <img src="/_img/flags/{!! e($admin['country']) !!}.gif"
                                        alt="{!! strtoupper(e($admin['country'])) !!}"
                                        class="tech-member__flag">
                                @else
                                    <img src="/_img/flags/unknown.gif" class="tech-member__flag">
                                @endif

                                <strong class="tech-member__name">
                                    {!! e($admin['display_name']) !!}
                                </strong>
                            </div>

                            <a href="/admin/manage-player/{!! urlencode((string)$steamid64Link) !!}" class="admin-btn">
                                <i class="fa-solid fa-user-gear"></i> Gérer
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </aside>

</div>
@endsection
