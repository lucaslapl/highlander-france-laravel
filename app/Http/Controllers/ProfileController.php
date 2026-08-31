<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Etf2lRepository;
use App\Models\PlayerRepository;
use App\Models\PlayerStatsRepository;
use App\Services\Auth;
use App\Services\SteamApi;
use App\Services\SteamId;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Profils joueurs : page publique, dashboard connecté, mises à jour et API stats.
 */
final class ProfileController extends Controller
{
    private const MODES = ['6s', '9v9'];

    private PlayerRepository $players;

    private PlayerStatsRepository $stats;

    private Etf2lRepository $etf2l;

    public function __construct()
    {
        $this->players = new PlayerRepository;
        $this->stats = new PlayerStatsRepository;
        $this->etf2l = new Etf2lRepository;
    }

    /**
     * GET /profile/{steamid} — profil public d'un joueur.
     */
    public function profil(Request $request): View
    {
        $steamid64 = (string) ($request->route('steamid') ?? $request->query('steamid', ''));

        if (! preg_match('/^\d{17}$/', $steamid64)) {
            abort(400);
        }

        $steamid3 = SteamId::toSteamId3($steamid64);
        $player = $this->players->findById($steamid3);

        if ($player === null) {
            abort(404);
        }

        $playerName = $player['display_name'] ?? $player['name'];

        return view('pages.profile.profil', $this->pageData([
            'title' => 'Highlander France - Profil de '.$playerName,
            'player' => $player,
            'playerName' => $playerName,
            'steamid64' => $steamid64,
            'steamid3' => $steamid3,
            'isOwnDashboard' => false,
        ]));
    }

    /**
     * GET /img/avatar/{steamid} — proxy de l'avatar Steam mis en cache localement.
     *
     * Le CDN de Steam (avatars.steamstatic.com) renvoie parfois des 429 quand le
     * navigateur charge l'image en direct. On sert donc l'avatar depuis notre
     * domaine : le serveur télécharge les octets une fois (avec les bons
     * en-têtes), les met en cache, puis les renvoie sans jamais retransmettre
     * de 429 au client.
     */
    public function avatar(Request $request, string $steamid): Response
    {
        $steamid3 = SteamId::toSteamId3($steamid);
        $player = $this->players->findById($steamid3);

        $source = is_string($player['avatar'] ?? null) ? $player['avatar'] : '';
        if ($source === '' || preg_match('#^https?://#', $source) !== 1) {
            abort(404);
        }

        $ext = strtolower((string) pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $ext = 'jpg';
        }
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'][$ext];

        $cacheDir = storage_path('app/avatars');
        $cacheFile = $cacheDir.'/'.md5($source).'.'.$ext;
        $ttl = 86400;

        $serve = function () use ($cacheFile, $mime): Response {
            $body = @file_get_contents($cacheFile);
            if ($body === false) {
                abort(404);
            }

            return new Response($body, 200, [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=86400',
                'Content-Length' => (string) strlen($body),
            ]);
        };

        if (is_file($cacheFile) && time() - (int) filemtime($cacheFile) < $ttl) {
            return $serve();
        }

        $ch = curl_init($source);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Referer: https://steamcommunity.com/'],
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data !== false && $code >= 200 && $code < 300 && $data !== '') {
            if (! is_dir($cacheDir) && @mkdir($cacheDir, 0775, true) === false && ! is_dir($cacheDir)) {
                // Répertoire inaccessible : on sert quand même les octets fraîchement récupérés.
            } else {
                @file_put_contents($cacheFile, $data);
            }

            return new Response($data, 200, [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=86400',
                'Content-Length' => (string) strlen($data),
            ]);
        }

        if (is_file($cacheFile)) {
            return $serve();
        }

        abort(404);
    }

    /**
     * GET /profile/dashboard — tableau de bord (lecture seule) du joueur connecté.
     */
    public function dashboard(): View|RedirectResponse
    {
        [$user, $steamid64, $steamid3] = $this->authenticatedPlayer();

        if ($user === null) {
            return redirect('/login');
        }

        $playerName = $user['display_name'] ?? $user['name'] ?? 'Joueur';

        return view('pages.profile.dashboard', $this->pageData([
            'title' => 'Highlander France - Mon profil',
            'description' => 'Tableau de bord privé de '.$playerName.' sur Highlander France : stats, activité et paramètres.',
            'noIndex' => true,
            'player' => $user,
            'playerName' => $playerName,
            'steamid64' => $steamid64,
            'steamid3' => $steamid3,
            'isOwnDashboard' => true,
            'isLocked' => (int) ($user['country_locked'] ?? 0),
            'nameChanged' => (int) ($user['name_changed'] ?? 0),
        ]));
    }

    /**
     * GET /profile/edit — page « Modifier mes informations » du joueur connecté.
     */
    public function edit(): View|RedirectResponse
    {
        [$user, $steamid64, $steamid3] = $this->authenticatedPlayer();

        if ($user === null) {
            return redirect('/login');
        }

        $playerName = $user['display_name'] ?? $user['name'] ?? 'Joueur';

        return view('pages.profile.edit', $this->pageData([
            'title' => 'Highlander France - Modifier mes informations',
            'description' => 'Modifier les informations de '.$playerName.' : pseudo, nationalité, liens et matériel.',
            'noIndex' => true,
            'player' => $user,
            'playerName' => $playerName,
            'steamid64' => $steamid64,
            'steamid3' => $steamid3,
            'isOwnDashboard' => true,
            'isLocked' => (int) ($user['country_locked'] ?? 0),
            'nameChanged' => (int) ($user['name_changed'] ?? 0),
        ]));
    }

    /**
     * Charge le joueur connecté (création + resync Steam au besoin).
     *
     * @return array{0: array<string, mixed>|null, 1: string, 2: string} [player, steamid64, steamid3]
     */
    private function authenticatedPlayer(): array
    {
        if (! Auth::isLoggedIn()) {
            return [null, '', ''];
        }

        $steamid64 = (string) Auth::steamId64();
        $steamid3 = SteamId::toSteamId3($steamid64);

        $user = $this->players->findById($steamid3);

        if ($user === null) {
            $this->players->createIfMissing($steamid3);
            $user = $this->players->findById($steamid3) ?? [];
        }

        // Synchronisation Steam si jamais faite ou périmée (> 24h)
        $lastUpdate = (int) ($user['last_updated'] ?? 0);
        if (empty($user['name']) || $lastUpdate < time() - 86400) {
            (new SteamApi)->syncProfile($steamid3);
            $user = $this->players->findById($steamid3) ?? $user;
        }

        return [$user, $steamid64, $steamid3];
    }

    /**
     * POST /profile/update-name — changement unique et définitif du pseudo.
     */
    public function updateName(Request $request): RedirectResponse
    {
        if (! Auth::isLoggedIn()) {
            return $this->flashError("Action refusée : vous devez être connecté pour modifier votre nom d'affichage.", '/');
        }

        $steamid3 = SteamId::toSteamId3((string) Auth::steamId64());
        $newName = trim((string) $request->input('display_name', ''));

        if ($this->players->hasNameChanged($steamid3)) {
            return $this->flashError("Vous avez déjà modifié votre nom d'affichage une fois. Action impossible.", '/profile/edit');
        }

        if ($newName === '') {
            return $this->flashError("Le nom d'affichage ne peut pas être vide.", '/profile/edit');
        }

        if (mb_strlen($newName) > 32) {
            return $this->flashError("Le nom d'affichage ne doit pas dépasser 32 caractères.", '/profile/edit');
        }

        $newName = strip_tags($newName);

        if ($this->players->updateDisplayName($steamid3, $newName)) {
            return $this->flashSuccess("Votre nom d'affichage a été définitivement enregistré !", '/profile/edit');
        }

        return $this->flashError("Une erreur est survenue lors de l'enregistrement.", '/profile/edit');
    }

    /**
     * POST /profile/update-country — choix unique et définitif de la nationalité.
     */
    public function updateCountry(Request $request): RedirectResponse
    {
        if (! Auth::isLoggedIn()) {
            return $this->flashError('Action refusée : vous devez être connecté pour modifier votre nationalité.', '/');
        }

        $steamid3 = SteamId::toSteamId3((string) Auth::steamId64());
        $chosenCountry = strtolower(trim((string) $request->input('country', '')));

        if ($chosenCountry === '' || ! in_array($chosenCountry, array_keys(config('hlfr.countries')), true)) {
            return $this->flashError('Pays invalide.', '/profile/edit');
        }

        if ($this->players->hasCountryLocked($steamid3)) {
            return $this->flashError('Votre nationalité est déjà verrouillée et ne peut plus être modifiée.', '/profile/edit');
        }

        if ($this->players->updateCountry($steamid3, $chosenCountry)) {
            return $this->flashSuccess('Votre nationalité a été enregistrée avec succès !', '/profile/edit');
        }

        return $this->flashError("Une erreur est survenue lors de l'enregistrement.", '/profile/edit');
    }

    /**
     * POST /profile/update-links — liens externes du profil (facultatifs, modifiables à volonté).
     */
    public function updateLinks(Request $request): RedirectResponse
    {
        if (! Auth::isLoggedIn()) {
            return $this->flashError('Action refusée : vous devez être connecté pour modifier vos liens.', '/');
        }

        $steamid3 = SteamId::toSteamId3((string) Auth::steamId64());
        $links = [];

        foreach (config('hlfr.profile_links') as $field => $meta) {
            $raw = trim((string) $request->input($field, ''));

            // Champ vide : on efface le lien enregistré.
            if ($raw === '') {
                $links[$field] = null;

                continue;
            }

            $raw = strip_tags($raw);

            if ($meta['type'] === 'url') {
                $error = $this->validateProfileUrl($raw, $meta['domains']);
                if ($error !== null) {
                    return $this->flashError($error, '/profile/edit');
                }
            } else {
                if (mb_strlen($raw) > (int) $meta['max_length']) {
                    return $this->flashError(sprintf(
                        'Le champ « %s » ne doit pas dépasser %d caractères.',
                        $meta['label'],
                        (int) $meta['max_length']
                    ), '/profile/edit');
                }
            }

            $links[$field] = $raw;
        }

        if ($this->players->updateProfileLinks($steamid3, $links)) {
            return $this->flashSuccess('Vos liens ont été enregistrés avec succès !', '/profile/edit');
        }

        return $this->flashError("Une erreur est survenue lors de l'enregistrement.", '/profile/edit');
    }

    /**
     * POST /profile/update-personal-info — date de naissance et matériel (facultatifs).
     */
    public function updatePersonalInfo(Request $request): RedirectResponse
    {
        if (! Auth::isLoggedIn()) {
            return $this->flashError('Action refusée : vous devez être connecté pour modifier ces informations.', '/');
        }

        $steamid3 = SteamId::toSteamId3((string) Auth::steamId64());

        // Date de naissance : facultative, mais valide si renseignée.
        $birthdateRaw = trim((string) $request->input('birthdate', ''));
        $birthdate = null;

        if ($birthdateRaw !== '') {
            $date = \DateTime::createFromFormat('Y-m-d', $birthdateRaw);

            if ($date === false || $date->format('Y-m-d') !== $birthdateRaw || $date > new \DateTime || $date->format('Y') < 1900) {
                return $this->flashError('Date de naissance invalide.', '/profile/edit');
            }

            $birthdate = $date->format('Y-m-d');
        }

        $gear = [];

        foreach (config('hlfr.profile_gear') as $field => $meta) {
            $raw = trim(strip_tags((string) $request->input($field, '')));

            if ($raw === '') {
                $gear[$field] = null;

                continue;
            }

            if (mb_strlen($raw) > 100) {
                return $this->flashError(sprintf(
                    'Le champ « %s » ne doit pas dépasser 100 caractères.',
                    $meta['label']
                ), '/profile/edit');
            }

            $gear[$field] = $raw;
        }

        if ($this->players->updatePersonalInfo($steamid3, $birthdate, $gear)) {
            return $this->flashSuccess('Vos informations personnelles ont été enregistrées !', '/profile/edit');
        }

        return $this->flashError("Une erreur est survenue lors de l'enregistrement.", '/profile/edit');
    }

    /**
     * Vérifie qu'une URL saisie par un joueur pointe vers un domaine autorisé.
     *
     * @param  array<int, string>  $allowedDomains
     */
    private function validateProfileUrl(string $url, array $allowedDomains): ?string
    {
        if (mb_strlen($url) > 255 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'URL invalide.';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return null;
            }
        }

        return sprintf("L'URL saisie ne pointe pas vers un domaine autorisé (%s).", implode(', ', $allowedDomains));
    }

    /**
     * GET /api/profile-stats?steamid=...&mode=... — statistiques JSON pour le profil.
     */
    public function profileStats(Request $request): JsonResponse
    {
        $steamid64 = (string) $request->query('steamid', '');
        $mode = (string) $request->query('mode', '9v9');

        if ($steamid64 === '' || ! preg_match('/^\d{17}$/', $steamid64) || ! in_array($mode, self::MODES, true)) {
            return response()->json(['error' => 'Paramètres invalides ou SteamID manquant.'], 400);
        }

        try {
            return response()
                ->json($this->statsForMode(SteamId::toSteamId3($steamid64), $mode))
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->header('Pragma', 'no-cache');
        } catch (\PDOException) {
            return response()->json(['error' => 'Erreur lors de la récupération des données.'], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function statsForMode(string $steamid3, string $mode): array
    {
        $matchStats = $this->stats->aggregate($steamid3, $mode);

        return [
            'total_matches' => $this->stats->totalMatches($steamid3, $mode),
            'top_maps' => $this->stats->topMaps($steamid3, $mode),
            'classes_played' => $this->stats->classesPlayed($steamid3, $mode),
            'recent_matches' => $this->stats->recentMatches($steamid3, $mode),
            'average_dpm' => $matchStats['average_dpm'],
            'average_dtpm' => $matchStats['average_dtpm'],
            'total_airshots' => $matchStats['total_airshots'],
            'total_captures' => $matchStats['total_captures'],
            'total_kills' => $matchStats['total_kills'],
            'total_deaths' => $matchStats['total_deaths'],
            'total_assists' => $matchStats['total_assists'],
            'kd_ratio' => $matchStats['kd_ratio'],
            'classes_killed' => $matchStats['classes_killed'],
        ];
    }

    /**
     * Données communes aux pages profil / dashboard (stats 9v9 + activité).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pageData(array $data): array
    {
        $steamid3 = $data['steamid3'];

        $rawDate = $data['player']['created_at'] ?? null;

        // Âge calculé depuis la date de naissance (facultative).
        $age = null;
        if (! empty($data['player']['birthdate'])) {
            try {
                $age = Carbon::parse($data['player']['birthdate'])->age;
            } catch (\Throwable) {
                $age = null;
            }
        }

        $avatar = $data['player']['avatar'] ?? null;
        $sameAs = [];
        foreach (config('hlfr.profile_links') as $field => $meta) {
            if (!empty($data['player'][$field]) && filter_var($data['player'][$field], FILTER_VALIDATE_URL)) {
                $sameAs[] = $data['player'][$field];
            }
        }
        if (!empty($data['player']['steamid'])) {
            $etf2lUrl = 'https://etf2l.org/forum/user/'.(int) $data['player']['steamid'];
        }
        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            'mainEntity' => array_filter([
                '@type' => 'Person',
                'name' => $data['playerName'],
                'url' => site_url().'/profile/'.$data['steamid64'],
                'image' => $avatar ?: null,
                'nationality' => $data['player']['country'] ?? null,
                'sameAs' => $sameAs !== [] ? $sameAs : null,
            ]),
        ];

        $isPrivate = !empty($data['noIndex']);
        $playerDescription = $data['description'] ?? 'Profil TF2 de '.$data['playerName'].' sur Highlander France : stats 9v9, divisions ETF2L et palmarès.';
        $breadcrumbs = $data['breadcrumbs'] ?? (!$isPrivate ? [
            ['name' => 'Accueil', 'url' => site_url().'/'],
            ['name' => $data['playerName'], 'url' => site_url().'/profile/'.$data['steamid64']],
        ] : null);

        return array_merge($data, [
            'description' => $playerDescription,
            'breadcrumbs' => $breadcrumbs,
            'styles' => ['/_css/profile.css'],
            'scripts' => [
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
                '/_js/profil.js',
            ],
            'stats' => $this->statsForMode($steamid3, '9v9'),
            'activityData' => $this->stats->activity($steamid3),
            'dateFormatee' => ! empty($rawDate) ? date('d/m/Y', strtotime((string) $rawDate)) : false,
            'countries' => config('hlfr.countries'),
            'country' => $data['player']['country'] ?? null,
            'etf2lLevels' => $this->etf2l->playerLevels($steamid3),
            'palmares' => $this->etf2l->playerPalmares($steamid3),
            'profileLinks' => config('hlfr.profile_links'),
            'profileGear' => config('hlfr.profile_gear'),
            'age' => $age,
            'structuredData' => $structuredData,
        ]);
    }

    private function flashError(string $message, string $url): RedirectResponse
    {
        return redirect($url)->with('error', $message);
    }

    private function flashSuccess(string $message, string $url): RedirectResponse
    {
        return redirect($url)->with('success', $message);
    }
}
