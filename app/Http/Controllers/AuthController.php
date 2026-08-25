<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlayerRepository;
use App\Services\SteamApi;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use xPaw\Steam\SteamOpenID;

/**
 * Authentification par OpenID Steam (login, callback, logout).
 *
 * Utilise xPaw/SteamOpenID : implémentation minimale, figée sur le serveur
 * Steam, sans découverte DNS (respecte la vie privée du serveur d'origine)
 * et immunisée contre les erreurs des bibliothèques OpenID génériques
 * (ex. « Path must not be empty » observé avec LightOpenID en production).
 */
final class AuthController extends Controller
{
    /**
     * GET /login — redirige vers l'OpenID Steam.
     */
    public function login(): RedirectResponse|View
    {
        try {
            $openid = new SteamOpenID(site_url() . '/auth/callback');

            return redirect()->away($openid->GetAuthUrl());
        } catch (\Throwable $e) {
            return view('pages.auth-error', [
                'title' => 'Erreur de connexion - ' . config('app.name'),
                'message' => 'Erreur : ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /auth/callback — retour de Steam OpenID.
     */
    public function callback(): View|RedirectResponse
    {
        try {
            $openid = new SteamOpenID(site_url() . '/auth/callback');

            // Connexion annulée par l'utilisateur sur Steam.
            if (request()->query('openid_mode') === 'cancel') {
                return view('pages.auth-error', [
                    'title' => 'Connexion annulée - ' . config('app.name'),
                    'message' => "Connexion annulée par l'utilisateur.",
                ]);
            }

            if (! $openid->ShouldValidate()) {
                return view('pages.auth-error', [
                    'title' => 'Erreur de connexion - ' . config('app.name'),
                    'message' => 'La validation a échoué (mode OpenID inattendu).',
                ]);
            }

            $steamid64 = $openid->Validate();
        } catch (\Throwable $e) {
            return view('pages.auth-error', [
                'title' => 'Erreur de connexion - ' . config('app.name'),
                'message' => 'La validation a échoué : ' . $e->getMessage(),
            ]);
        }

        $steamid3 = SteamId::toSteamId3($steamid64);

        Session::regenerate();
        Session::put('steamid', $steamid64);

        $repo = new PlayerRepository();
        $steamApi = new SteamApi();
        $user = $repo->findById($steamid3);

        if ($user === null) {
            // Nouvel inscrit : création puis synchronisation Steam
            $repo->createIfMissing($steamid3);
            $steamApi->syncOrCreatePlayer($steamid64);

            // Un nouvel inscrit n'est jamais admin par défaut
            Session::put('is_admin', false);
        } else {
            // Compte existant : created_at manquant = première connexion
            $repo->ensureCreatedAt($steamid3);

            // Joueur inscrit sans jamais avoir été synchronisé
            if (empty($user['name']) || $user['name'] === 'Nouveau Joueur') {
                $steamApi->syncOrCreatePlayer($steamid64);
            }

            Session::put('is_admin', (isset($user['is_admin']) && (int) $user['is_admin'] === 1));
        }

        return redirect('/profile/dashboard');
    }

    /**
     * GET /logout — détruit la session puis redirige vers l'accueil.
     */
    public function logout(): RedirectResponse
    {
        Session::flush();
        Session::invalidate();

        return redirect('/');
    }
}
