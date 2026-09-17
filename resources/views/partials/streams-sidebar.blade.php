{{-- Encadré sidebar : streamers français actuellement en direct sur Twitch. --}}
{{-- Le bloc reste masqué tant qu'aucun stream FR TF2 / HL France n'est en --}}
{{-- ligne ; il est rempli côté client par le polling de /api/twitch-sidebar --}}
{{-- (cache alimenté par le CRON app:sync-twitch). --}}
<div class="sidebar-card twitch-streams" id="twitch-streams" hidden>
    <div class="sidebar-card-header">
        <h3><i class="fa-brands fa-twitch"></i> Streamers en direct</h3>
    </div>
    <ul class="twitch-streams-list" id="twitch-streams-list"></ul>
</div>