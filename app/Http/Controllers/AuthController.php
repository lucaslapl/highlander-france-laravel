<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlayerRepository;
use App\Services\SteamApi;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use LightOpenID;

/**
 * Authentification par OpenID Steam (login, callback, logout).
 */
final class AuthController extends Controller
{
    /**
     * GET /login — redirige vers l'OpenID Steam.
     */
    public function login(): \Illuminate\Http\RedirectResponse|View
    {
        try {
            $openid = $this->openid();
            $openid->returnUrl = site_url() . '/auth/callback';
            $openid->identity = 'https://steamcommunity.com/openid';

            return redirect()->away($openid->authUrl());
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
    public function callback(): View|\Illuminate\Http\RedirectResponse
    {
        $openid = $this->openid();

        if ($openid->mode == 'cancel') {
            return view('pages.auth-error', [
                'title' => 'Connexion annulée - ' . config('app.name'),
                'message' => "Connexion annulée par l'utilisateur.",
            ]);
        }

        if (!$openid->validate()) {
            return view('pages.auth-error', [
                'title' => 'Erreur de connexion - ' . config('app.name'),
                'message' => 'La validation a échoué.',
            ]);
        }

        $steamid64 = basename((string) $openid->identity);
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
    public function logout(): \Illuminate\Http\RedirectResponse
    {
        Session::flush();
        Session::invalidate();

        return redirect('/');
    }

    /**
     * Instance LightOpenID configurée pour l'environnement courant.
     */
    private function openid(): LightOpenID
    {
        $openid = new LightOpenID((string) parse_url(site_url(), PHP_URL_HOST));
        // Politique SSL commune : vérifié en production, désactivé en WAMP (pas de bundle CA).
        $openid->verify_peer = (bool) config('hlfr.curl_verify_ssl');

        return $openid;
    }
}
