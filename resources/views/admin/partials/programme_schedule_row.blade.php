{{-- Une entrée du « Programme de la semaine » de l'éditeur Overlay
     Programme. Rendue côté serveur pour les entrées existantes, et via
     le <template> du même éditeur pour les nouvelles entrées ajoutées
     en JavaScript (l'index __INDEX__ est remplacé à la volée).
     $entry peut être null (nouvelle ligne vide). --}}
@php
    $entry = $entry ?? [];
    $day = (string) ($entry['day'] ?? '');
    $time = (string) ($entry['time'] ?? '');
    $label = (string) ($entry['label'] ?? '');
    $teamA = (string) ($entry['team_a'] ?? '');
    $teamB = (string) ($entry['team_b'] ?? '');
@endphp
<div class="programme-schedule-row js-programme-schedule-row"
     style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; border:1px solid #333; border-radius:8px; padding:12px; margin-bottom:10px;">
    <div style="flex:1; min-width:200px;">
        <label class="admin-form-label">Date (ex : « Dimanche 11 Octobre »)</label>
        <input type="text" name="schedule[{{ $i }}][day]" class="form-control" maxlength="64"
               value="{{ $day }}" placeholder="Dimanche 11 Octobre">
    </div>
    <div style="width:110px;">
        <label class="admin-form-label">Heure</label>
        <input type="text" name="schedule[{{ $i }}][time]" class="form-control" maxlength="16"
               value="{{ $time }}" placeholder="21h">
    </div>
    <div style="flex:1; min-width:220px;">
        <label class="admin-form-label">Libellé (tour / division)</label>
        <input type="text" name="schedule[{{ $i }}][label]" class="form-control" maxlength="120"
               value="{{ $label }}" placeholder="Finale Loser Bracket - Prem">
    </div>
    <div style="flex:1; min-width:160px;">
        <label class="admin-form-label">Équipe 1</label>
        <input type="text" name="schedule[{{ $i }}][team_a]" class="form-control js-programme-row-team-a" maxlength="64"
               value="{{ $teamA }}">
    </div>
    <div style="flex:1; min-width:160px;">
        <label class="admin-form-label">Équipe 2</label>
        <input type="text" name="schedule[{{ $i }}][team_b]" class="form-control js-programme-row-team-b" maxlength="64"
               value="{{ $teamB }}">
    </div>
    <button type="button" class="admin-btn admin-btn--danger js-programme-schedule-remove">Retirer</button>
</div>
