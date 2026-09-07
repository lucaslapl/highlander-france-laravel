@php
    $mapThumb = $map['thumb_url'] ?? null;
    $mapExists = $map['bsp_exists'] ?? false;
    $mapActive = (int) ($map['is_active'] ?? 1) === 1;
@endphp
<tr class="{{ $mapActive ? '' : 'adm-maps-row-inactive' }}">
    <td>
        @if ($mapThumb)
            <img loading="lazy" decoding="async" src="{{ e($mapThumb) }}" alt="{{ e($map['name']) }}"
                 class="adm-maps-thumb">
        @else
            <div class="adm-maps-thumb adm-maps-thumb--empty">
                <i class="fa-solid fa-image"></i>
            </div>
        @endif
    </td>
    <td>
        <strong style="color: #fff;">{{ e($map['label']) }}</strong>
        <div style="font-size: 12px; color: #777; font-family: monospace;">{{ e($map['name']) }}</div>
    </td>
    <td>
        @if ($mapExists)
            <a href="{{ e($map['bsp_url']) }}" target="_blank" class="admin-mono" style="color: #2ecc71;">
                <i class="fa-solid fa-file"></i> {{ e($map['bsp_file']) }}
            </a>
            <div style="font-size: 12px; color: #888;">{{ e($map['bsp_size_human']) }}</div>
        @else
            <span class="admin-mono" style="color: #aaa;">
                <i class="fa-solid fa-file"></i> {{ e($map['bsp_file'] ?? '—') }}
            </span>
            <div style="font-size: 12px; color: #e67e22;">Fichier non présent</div>
        @endif
    </td>
    <td class="text-center">
        <span class="status-pill" style="--accent: {{ $mapActive ? '#2ecc71' : '#e74c3c' }};">
            {{ $mapActive ? 'Actif' : 'Inactif' }}
        </span>
    </td>
    <td class="text-center">
        <div class="adm-maps-actions">
            <button type="button" class="admin-btn btn-icon adm-maps-edit"
                    title="Modifier" style="color: #ffd166;"
                    data-id="{{ (int) $map['id'] }}"
                    data-name="{{ e($map['name']) }}"
                    data-label="{{ e($map['label']) }}"
                    data-category="{{ e($map['category']) }}"
                    data-active="{{ (int) ($map['is_active'] ?? 1) }}">
                <i class="fa-solid fa-pen"></i>
            </button>
            <form action="/admin/maps/{{ (int) $map['id'] }}/toggle" method="POST" style="display: inline;">
                @csrf
                <button type="submit" class="admin-btn btn-icon" title="{{ $mapActive ? 'Désactiver' : 'Activer' }}"
                        style="color: {{ $mapActive ? '#e74c3c' : '#2ecc71' }};">
                    <i class="fa-solid fa-{{ $mapActive ? 'eye-slash' : 'eye' }}"></i>
                </button>
            </form>
            <form action="/admin/maps/{{ (int) $map['id'] }}/delete" method="POST" style="display: inline;"
                  onsubmit="return confirm('Supprimer la map « {{ e($map['label']) }} » ? Le fichier .bsp et la miniature seront supprimés.');">
                @csrf
                <button type="submit" class="admin-btn btn-icon" title="Supprimer" style="color: #e74c3c;">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </form>
        </div>
    </td>
</tr>