<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accès strict réservé aux administrateurs (panel /admin/*).
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::isLoggedIn() || ! Auth::isAdmin()) {
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Accès refusé.'], 403);
            }

            return response()->view('errors.403', ['title' => 'Accès refusé - ' . config('app.name')], 403);
        }

        return $next($request);
    }
}
