{{-- Lien de retour vers le panel admin depuis les pages d'outils overlay :
     les admins retournent au dashboard complet, les casters / prod au panel
     restreint /admin/panel (leur unique point d'entrée). --}}
@php
    $panelUrl = \App\Services\Auth::isAdmin() ? '/admin/dashboard' : '/admin/panel';
@endphp
<a href="{{ $panelUrl }}" class="admin-link-btn" style="display:inline-flex; align-items:center; gap:6px; margin-bottom:16px;">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Retour au panel admin
</a>
