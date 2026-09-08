@php
    $mode = $team['mode'] ?? null;
@endphp
<div class="admin-card sd-team" data-team-id="{{ (int) $team['team_id'] }}">
    <h3 class="admin-card__title sd-team-title">
        <i class="fa-solid fa-shield-halved"></i>
        <a href="https://etf2l.org/tf2/team/{{ (int) $team['team_id'] }}/" target="_blank" rel="noopener">
            {{ e($team['name']) }}
        </a>
        @if (!empty($team['tag']))
            <span class="sd-tag">{{ e($team['tag']) }}</span>
        @endif
        <span class="status-pill" style="--accent: #2ec4b6;">
            {{ $mode ? mb_strtoupper($mode) : '—' }}{{ $team['mode_override'] ? '' : ' (auto)' }}
        </span>
        @if ($team['blacklisted'])
            <span class="status-pill" style="--accent: #e74c3c;">blacklistée</span>
        @endif
    </h3>

    {{-- Cartes de stats de l'équipe --}}
    @if ($team['stats'] !== null)
        <div class="sd-stats">
            <div class="sd-stat">
                <div class="sd-stat__value">{{ $team['stats']['matches'] }}</div>
                <div class="sd-stat__label">Matchs</div>
            </div>
            <div class="sd-stat">
                <div class="sd-stat__value">{{ $team['stats']['wins'] }}V / {{ $team['stats']['losses'] }}D</div>
                <div class="sd-stat__label">Victoires / défaites</div>
            </div>
            <div class="sd-stat">
                <div class="sd-stat__value">{{ $team['stats']['winrate'] !== null ? $team['stats']['winrate'].' %' : '—' }}</div>
                <div class="sd-stat__label">Winrate</div>
            </div>
            <div class="sd-stat">
                <div class="sd-stat__value">{{ $team['stats']['kd'] }}</div>
                <div class="sd-stat__label">K/D</div>
            </div>
            <div class="sd-stat">
                <div class="sd-stat__value">{{ number_format((int) $team['stats']['dpm'], 0, ',', ' ') }}</div>
                <div class="sd-stat__label">DPM</div>
            </div>
            <div class="sd-stat">
                <div class="sd-stat__value">{{ number_format((int) $team['stats']['dmg'], 0, ',', ' ') }}</div>
                <div class="sd-stat__label">Dégâts</div>
            </div>
            <div class="sd-stat">
                <div class="sd-stat__value">{{ $team['stats']['airshots'] }}</div>
                <div class="sd-stat__label">Aériens</div>
            </div>
            <div class="sd-stat">
                <div class="sd-stat__value">{{ $team['stats']['captures'] }}</div>
                <div class="sd-stat__label">Captures</div>
            </div>
        </div>
    @else
        <p class="admin-hint">
            @if ($team['roster_count'] === 0)
                Aucun roster synchronisé pour cette équipe.
            @elseif ($team['has_history'])
                Aucune stat pour le moment (logs manquants ?).
            @else
                Historique ETF2L indisponible.
            @endif
        </p>
    @endif

    {{-- Actions globales de l'équipe --}}
    <div class="sd-actions">
        <form action="/admin/stats-duel/scrape" method="POST">
            @csrf
            <input type="hidden" name="team_id" value="{{ (int) $team['team_id'] }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <button type="submit" class="admin-btn admin-btn--xs" style="--accent: #2ec4b6;">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Récupérer les logs manquants
            </button>
        </form>
        <form action="/admin/stats-duel/recompute" method="POST">
            @csrf
            <input type="hidden" name="team_id" value="{{ (int) $team['team_id'] }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <button type="submit" class="admin-btn admin-btn--xs" style="--accent: #3498db;">
                <i class="fa-solid fa-arrows-rotate"></i> Recalculer les stats
            </button>
        </form>
        <form action="/admin/stats-duel/refresh-history" method="POST"
              onsubmit="return confirm('Forcer le re-téléchargement de l\'historique ETF2L des joueurs ?');">
            @csrf
            <input type="hidden" name="team_id" value="{{ (int) $team['team_id'] }}">
            <button type="submit" class="admin-btn admin-btn--xs">
                <i class="fa-solid fa-cloud-arrow-down"></i> Rafraîchir l'historique ETF2L
            </button>
        </form>
        <label class="sd-filter-playing">
            <input type="checkbox" class="sd-only-playing"> N'afficher que les joueurs cochés
        </label>
    </div>

    {{-- Tableau des joueurs --}}
    <h4 class="sd-subtitle"><i class="fa-solid fa-user-group"></i> Joueurs <small>(3 dernières saisons avec l'équipe)</small></h4>
    @if ($team['players'] === [])
        <p class="admin-hint">Aucun joueur avec des stats sur la fenêtre.</p>
    @else
        <div class="admin-table-scroll">
            <table class="admin-table sd-players">
                <thead>
                    <tr>
                        <th class="text-center" title="Joueur présent le jour du match ?">Jouera</th>
                        <th>Joueur</th>
                        <th>Rôle</th>
                        <th class="num">Matchs</th>
                        <th class="num">Destr.</th>
                        <th class="num">Décès</th>
                        <th class="num">K/D</th>
                        <th class="num">DPM</th>
                        <th class="num">Winrate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($team['players'] as $player)
                        <tr class="sd-player-row {{ !$player['visible'] ? 'is-hidden' : '' }}" data-steamid="{{ $player['steamid'] }}">
                            <td class="text-center">
                                <input type="checkbox" class="sd-player-vis" {{ $player['visible'] ? 'checked' : '' }}>
                            </td>
                            <td>
                                <div class="sd-player">
                                    @if ($player['avatar'])
                                        <img class="sd-avatar" src="{{ $player['avatar'] }}" alt="" loading="lazy">
                                    @endif
                                    <span>{{ e($player['name']) }}</span>
                                </div>
                            </td>
                            <td>
                                @if (stripos($player['role'], 'leader') !== false || stripos($player['role'], 'alpha stag') !== false)
                                    <span class="sd-role sd-role--lead">{{ e($player['role']) }}</span>
                                @else
                                    <span class="sd-role">{{ e($player['role']) }}</span>
                                @endif
                            </td>
                            <td class="num">{{ $player['matches'] }}</td>
                            <td class="num">{{ $player['kills'] }}</td>
                            <td class="num">{{ $player['deaths'] }}</td>
                            <td class="num">{{ $player['kd'] }}</td>
                            <td class="num">{{ number_format((int) $player['dpm'], 0, ',', ' ') }}</td>
                            <td class="num">{{ $player['winrate'] !== null ? $player['winrate'].' %' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Matchs officiels de la fenêtre --}}
    <h4 class="sd-subtitle">
        <i class="fa-solid fa-trophy"></i> Matchs officiels
        <small>({{ count($team['matches']) }})</small>
    </h4>
    @if ($team['matches'] === [])
        <p class="admin-hint">Aucun match officiel trouvé pour cette équipe sur la période.</p>
    @else
        <div class="admin-table-scroll">
            <table class="admin-table sd-matches">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Compétition</th>
                        <th>Adversaire</th>
                        <th class="text-center">Score off.</th>
                        <th>Logs</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($team['matches'] as $match)
                        <tr>
                            <td class="nowrap">
                                @if (!empty($match['time']))
                                    {{ \Carbon\CarbonImmutable::createFromTimestamp((int) $match['time'])->format('d/m/Y') }}
                                @else
                                    —
                                @endif
                                @if (!empty($match['season_label']))
                                    <span class="sd-season">S{{ e($match['season_label']) }}</span>
                                @endif
                            </td>
                            <td>
                                {{ e($match['competition_name']) }}
                                @if (!empty($match['round']))
                                    <div class="sd-round">{{ e($match['round']) }}</div>
                                @endif
                            </td>
                            <td>
                                @if (!empty($match['opponent_name']))
                                    <a href="https://etf2l.org/tf2/team/{{ (int) $match['opponent_team_id'] }}/" target="_blank" rel="noopener">
                                        {{ e($match['opponent_name']) }}
                                    </a>
                                @else
                                    <span class="admin-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center nowrap">
                                @if ($match['team_score'] !== null)
                                    <span class="sd-score">
                                        <span class="sd-red">{{ $match['team_score'] }}</span>–<span class="sd-blue">{{ $match['opponent_score'] }}</span>
                                    </span>
                                @else
                                    <span class="admin-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($match['logs'] !== [])
                                    @foreach ($match['logs'] as $log)
                                        <span class="liga-log-chip {{ $log['blacklisted'] ? 'liga-log-chip--danger' : '' }}">
                                            <a href="https://logs.tf/{{ $log['log_id'] }}" target="_blank" rel="noopener">
                                                #{{ $log['log_id'] }}
                                            </a>
                                            @if ($log['red_score'] !== null)
                                                <span class="liga-log-score">
                                                    <span class="liga-red">{{ $log['red_score'] }}</span>–<span class="liga-blue">{{ $log['blue_score'] }}</span>
                                                </span>
                                            @endif
                                            @if ($log['processed'])
                                                <span class="sd-chip" title="Intégré aux stats">✓</span>
                                            @endif
                                            <span class="liga-log-src" title="Source">{{ $log['source'] === 'auto' ? 'auto' : 'manuel' }}</span>
                                        </span>
                                    @endforeach
                                @else
                                    <span class="status-pill" style="--accent: #f39c12;">
                                        <i class="fa-solid fa-hourglass-half"></i> Log manquant
                                    </span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="sd-match-actions">
                                    <form action="/admin/stats-duel/scrape" method="POST" title="Chercher le log sur la page ETF2L">
                                        @csrf
                                        <input type="hidden" name="team_id" value="{{ (int) $team['team_id'] }}">
                                        <input type="hidden" name="mode" value="{{ $mode }}">
                                        <input type="hidden" name="match_id" value="{{ (int) $match['match_id'] }}">
                                        <button type="submit" class="admin-btn admin-btn--xs" style="--accent: #2ec4b6;">
                                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                                        </button>
                                    </form>
                                    <form action="/admin/stats-duel/attach-log" method="POST" class="liga-attach">
                                        @csrf
                                        <input type="hidden" name="team_id" value="{{ (int) $team['team_id'] }}">
                                        <input type="hidden" name="mode" value="{{ $mode }}">
                                        <input type="hidden" name="match_id" value="{{ (int) $match['match_id'] }}">
                                        <input type="text" name="log_url" class="form-control form-control--sm"
                                               placeholder="URL / ID logs.tf" required>
                                        <button type="submit" class="admin-btn admin-btn--xs" style="--accent: #2ec4b6;">
                                            <i class="fa-solid fa-paperclip"></i>
                                        </button>
                                    </form>
                                    @foreach ($match['logs'] as $log)
                                        <form action="/admin/stats-duel/detach-log" method="POST" title="Retirer le log">
                                            @csrf
                                            <input type="hidden" name="log_id" value="{{ $log['log_id'] }}">
                                            <button type="submit" class="admin-btn admin-btn--xs admin-btn--danger">
                                                <i class="fa-solid fa-link-slash"></i>
                                            </button>
                                        </form>
                                        <form action="{{ $log['blacklisted'] ? '/admin/stats-duel/unblacklist-log' : '/admin/stats-duel/blacklist-log' }}" method="POST"
                                              title="{{ $log['blacklisted'] ? 'Réintégrer le log' : 'Blacklister le log (exclu des stats)' }}">
                                            @csrf
                                            <input type="hidden" name="log_id" value="{{ $log['log_id'] }}">
                                            @if (!$log['blacklisted'])
                                                <input type="hidden" name="reason" value="Exclu manuellement depuis les stats équipes">
                                            @endif
                                            <button type="submit" class="admin-btn admin-btn--xs {{ $log['blacklisted'] ? 'admin-btn--success' : 'admin-btn--danger' }}">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
