{{-- Une ligne du classement dans l'éditeur admin : équipe (nom, avatar,
     pays) et colonnes ETF2L (MJ, G, P, points, pénalité). L'index de ligne
     est réécrit par admin_bracket.js à l'ajout / suppression. --}}
@php
    $rIdx = (string) $index;
@endphp
<div class="table-row"
     style="border:1px solid #444; border-radius:8px; padding:12px; margin-bottom:10px; background:rgba(0,0,0,0.15);">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:2; min-width:220px;">
            <label class="admin-form-label">Équipe</label>
            <input type="text" name="rows[{{ $rIdx }}][name]" class="form-control" maxlength="64"
                   value="{{ e((string) ($row['name'] ?? '')) }}">
        </div>
        <div style="flex:2; min-width:200px;">
            <label class="admin-form-label">Avatar par URL (optionnel)</label>
            <input type="url" name="rows[{{ $rIdx }}][avatar]" class="form-control" maxlength="500"
                   placeholder="https://…/logo.png" value="{{ e((string) ($row['avatar'] ?? '')) }}">
        </div>
        <div style="flex:1; min-width:120px;">
            <label class="admin-form-label">Pays (optionnel)</label>
            <input type="text" name="rows[{{ $rIdx }}][country]" class="form-control" maxlength="32"
                   placeholder="France" value="{{ e((string) ($row['country'] ?? '')) }}">
        </div>
        <div style="width:80px;">
            <label class="admin-form-label">MJ</label>
            <input type="number" name="rows[{{ $rIdx }}][played]" class="form-control" min="0" max="9999"
                   value="{{ (int) ($row['played'] ?? 0) }}">
        </div>
        <div style="width:80px;">
            <label class="admin-form-label">G</label>
            <input type="number" name="rows[{{ $rIdx }}][won]" class="form-control" min="0" max="9999"
                   value="{{ (int) ($row['won'] ?? 0) }}">
        </div>
        <div style="width:80px;">
            <label class="admin-form-label">P</label>
            <input type="number" name="rows[{{ $rIdx }}][lost]" class="form-control" min="0" max="9999"
                   value="{{ (int) ($row['lost'] ?? 0) }}">
        </div>
        <div style="width:90px;">
            <label class="admin-form-label">Pts</label>
            <input type="number" name="rows[{{ $rIdx }}][score]" class="form-control" min="0" max="9999"
                   value="{{ (int) ($row['score'] ?? 0) }}">
        </div>
        <div style="width:90px;">
            <label class="admin-form-label">Pén</label>
            <input type="number" name="rows[{{ $rIdx }}][penalty]" class="form-control" min="0" max="9999"
                   value="{{ (int) ($row['penalty'] ?? 0) }}">
        </div>
        <button type="button" class="admin-btn js-move" data-target="row" data-dir="-1" title="Monter la ligne">
            <i class="fa-solid fa-arrow-up"></i>
        </button>
        <button type="button" class="admin-btn js-move" data-target="row" data-dir="1" title="Descendre la ligne">
            <i class="fa-solid fa-arrow-down"></i>
        </button>
        <button type="button" class="admin-btn admin-btn--danger js-remove" data-target="row" title="Supprimer la ligne">
            <i class="fa-solid fa-trash"></i>
        </button>
    </div>
</div>
