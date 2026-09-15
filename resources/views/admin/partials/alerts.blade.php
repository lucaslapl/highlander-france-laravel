{{-- Messages flash (succès / erreur / info) en style admin. --}}
@if (session('success'))
    <div class="admin-alert admin-alert--success"><i class="fa-solid fa-circle-check"></i> {{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="admin-alert admin-alert--error"><i class="fa-solid fa-circle-xmark"></i> {{ session('error') }}</div>
@endif
@if (session('info'))
    <div class="admin-alert admin-alert--info"><i class="fa-solid fa-circle-info"></i> {{ session('info') }}</div>
@endif