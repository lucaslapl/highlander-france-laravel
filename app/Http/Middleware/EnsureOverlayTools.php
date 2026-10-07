<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accès aux outils d'overlay OBS (/admin/overlay, /admin/series et le hub
 * /admin/overlays-stream) : réservé aux administrateurs et aux rôles
 * caster / prod (team Twitch). Les casters et prod ne voient rien d'autre
 * du panel admin (cf. EnsureAdmin pour les autres routes /admin/*).
 */
final class EnsureOverlayTools
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::canAccessOverlayTools()) {
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Accès refusé.'], 403);
            }

            return response()->view('errors.403', ['title' => 'Accès refusé - ' . config('app.name')], 403);
        }

        return $next($request);
    }
}
