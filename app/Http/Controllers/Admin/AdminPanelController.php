<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth;
use Illuminate\Contracts\View\View;

/**
 * Hub des outils d'overlay OBS (/admin/overlays-stream) : la porte d'entrée
 * unique vers les quatre outils de broadcast (logs, scores, bracket, pick/ban)
 * pour les administrateurs comme pour les rôles caster / prod (team Twitch).
 * Aucune statistique du site n'y est exposée.
 */
final class AdminPanelController extends Controller
{
    /**
     * GET /admin/overlays-stream — titre + boutons vers les outils d'overlay.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.panel', [
            'title' => 'Overlays Stream - '.config('app.name'),
            'description' => 'Overlays Stream : outils d\'overlay OBS pour les broadcasts Highlander France.',
        ]);
    }
}
