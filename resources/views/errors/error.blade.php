
<div class="error-page" style="text-align: center; padding: 60px 20px;">
    <h2 style="font-size: 3rem; margin: 0;">{{ (int)($code ?? 500) }}</h2>
    <p>{!! e($message ?? 'Une erreur est survenue.') !!}</p>
    @if (!empty($detail))
        <p style="font-family: monospace; font-size: 13px; color: #888; background: #1a1a1a; padding: 12px; border-radius: 4px; display: inline-block; max-width: 100%; word-break: break-word;">{!! e($detail) !!}</p>
    @endif
    <p><a href="/" style="color: #ff7b00;">Retour à l'accueil</a></p>
</div>
