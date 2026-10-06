<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth;
use Illuminate\Contracts\View\View;

/**
 * Panel admin restreint (/admin/panel) : la seule porte d'entrée du panel
 * pour les rôles caster / prod (team Twitch). Ils n'y voient que le titre
 * et l'accès aux deux outils d'overlay OBS — aucune statistique du site.
 */
final class AdminPanelController extends Controller
{
    /**
     * GET /admin/panel — titre + boutons vers les outils d'overlay.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.panel', [
            'title' => 'Panel admin - '.config('app.name'),
            'description' => 'Panel admin restreint : outils d\'overlay OBS pour les broadcasts Highlander France.',
        ]);
    }
}
