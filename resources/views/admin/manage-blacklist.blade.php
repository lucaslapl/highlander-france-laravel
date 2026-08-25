@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #f35f5f;">
    <h2><i class="fa-solid fa-ban"></i> Gestion des logs blacklistés</h2>
    <p>
        Les logs présents ici sont exclus des Match Stats, des stats de l'accueil et des statistiques joueurs.
    </p>
</div>

<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-plus"></i> Ajouter un log à la blacklist
    </h3>
    <form action="/api/admin/blacklist" method="POST" class="admin-form-stack">
        @csrf
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <label for="log_id">ID du log (logs.tf) :</label>
            <input type="text" name="log_id" id="log_id" class="form-control" placeholder="Ex : 4062936" pattern="[0-9]+" required>
        </div>
        <div class="form-group">
            <label for="reason">Raison (facultatif) :</label>
            <input type="text" name="reason" id="reason" class="form-control" placeholder="Ex : Log de test, stats fausses...">
        </div>
        <div>
            <button type="submit" class="admin-btn admin-btn--primary" style="--accent: #f35f5f;">
                <i class="fa-solid fa-ban"></i> Blacklister ce log
            </button>
        </div>
    </form>
</div>

<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-list"></i> Logs blacklistés
        <span class="status-pill" style="--accent: #f35f5f;">
            {{ (int)$totalBlacklisted }}
        </span>
    </h3>

    @if (empty($blacklist))
        <p style="color: #aaa; font-style: italic; font-size: 14px;">Aucun log blacklisté pour le moment.</p>
    @else
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Log ID</th>
                    <th>Raison</th>
                    <th>Ajouté par</th>
                    <th>Date</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($blacklist as $entry)
                    <tr>
                        <td>
                            <a href="https://logs.tf/{{ (int)$entry['log_id'] }}" target="_blank" class="admin-mono">
                                {{ (int)$entry['log_id'] }}
                            </a>
                        </td>
                        <td style="color: #ccc;">{!! e($entry['reason'] ?: '—') !!}</td>
                        <td style="color: #aaa; font-size: 13px;">
                            {!! e($entry['admin_name'] ?: '—') !!}
                            @if (!empty($entry['added_by']) && preg_match('/^\d{17}$/', (string)$entry['added_by']))
                                <i class="fa-solid fa-circle-info" style="color: #555; cursor: help; margin-left: 4px;"
                                    title="SteamID64 : {!! e($entry['added_by']) !!}"></i>
                            @endif
                        </td>
                        <td style="color: #aaa; font-size: 13px;">{!! !empty($entry['created_at']) ? date('d/m/Y H:i', strtotime((string)$entry['created_at'])) : '—' !!}</td>
                        <td class="text-center">
                            <form action="/api/admin/blacklist" method="POST" style="display: inline;"
                                onsubmit="return confirm('Retirer le log #{{ (int)$entry['log_id'] }} de la blacklist ?');">
                                @csrf
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="log_id" value="{{ (int)$entry['log_id'] }}">
                                <button type="submit" class="admin-btn admin-btn--success">
                                    <i class="fa-solid fa-rotate-left"></i> Restaurer
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
