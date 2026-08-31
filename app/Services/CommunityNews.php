<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Agrégateur des actualités communautaires TF2 affichées dans la sidebar de
 * l'accueil : flux RSS ETF2L et teamfortress.com, et scraping de la page news
 * de teamfortress.tv (qui n'expose plus de flux RSS).
 *
 * Résultats mis en cache : la récupération se fait côté serveur pour éviter
 * les restrictions CORS du navigateur et limiter la charge sur les sources.
 */
final class CommunityNews
{
    private const CACHE_KEY = 'community_news';

    private const CACHE_TTL = 30; // minutes

    /** Ordre d'affichage des sources dans la sidebar. */
    public const SOURCE_ORDER = ['etf2l', 'teamfortress', 'tftv'];

    public const SOURCE_LABELS = [
        'etf2l' => 'ETF2L',
        'teamfortress' => 'teamfortress.com',
        'tftv' => 'teamfortress.tv',
    ];

    /** Logos locaux (favicons) associés à chaque source, affichés avant la date. */
    public const SOURCE_LOGO = [
        'etf2l' => '/_img/src/etf2l.ico',
        'teamfortress' => '/_img/src/tf2com.ico',
        'tftv' => '/_img/src/tftv.ico',
    ];

    /**
     * News les plus récentes (toutes sources confondues, triées par date
     * décroissante), limitées au nombre demandé.
     *
     * @return array<int, array<string, mixed>> Items avec clés source, source_label,
     *                                          logo, title, url, date, date_label.
     */
    public function news(int $max = 5): array
    {
        $fresh = Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_TTL), function () use ($max): array {
            // On récupère un peu plus par source pour avoir assez de candidats,
            // puis on restreint aux plus récentes.
            $items = $this->gather(min(max($max * 2, $max + 5), 12));

            return array_slice($items, 0, max(1, $max));
        });

        return is_array($fresh) ? $fresh : [];
    }

    /**
     * Récupère chaque source indépendamment (une en échec ne fait pas échouer
     * les autres), puis fusionne et trie le tout par date décroissante.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gather(int $perSource): array
    {
        $fetchers = [
            'etf2l' => fn (): array => $this->etf2l($perSource),
            'teamfortress' => fn (): array => $this->teamfortressCom($perSource),
            'tftv' => fn (): array => $this->teamfortressTv($perSource),
        ];

        $items = [];
        foreach (self::SOURCE_ORDER as $key) {
            try {
                foreach ($fetchers[$key]() as $item) {
                    $items[] = [
                        'source' => $key,
                        'source_label' => self::SOURCE_LABELS[$key],
                        'logo' => self::SOURCE_LOGO[$key],
                    ] + $item;
                }
            } catch (\Throwable $e) {
                error_log('CommunityNews['.$key.'] : '.$e->getMessage());
            }
        }

        // Les items sans date (théoriquement rares) restent en bas.
        usort($items, static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));

        return $items;
    }

    /**
     * Flux RSS du blog officiel teamfortress.com.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamfortressCom(int $limit): array
    {
        return $this->parseXmlFeed('https://www.teamfortress.com/rss.xml', $limit);
    }

    /**
     * Flux RSS (WordPress) ETF2L.
     *
     * @return array<int, array<string, mixed>>
     */
    private function etf2l(int $limit): array
    {
        return $this->parseXmlFeed('https://etf2l.org/feed/', $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseXmlFeed(string $url, int $limit): array
    {
        $raw = JsonClient::getRaw($url);
        if ($raw['http_code'] !== 200 || $raw['body'] === null || $raw['body'] === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw['body']);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        $items = [];
        $channel = $xml->channel ?? null;
        foreach (($channel->item ?? $xml->item) ?: [] as $item) {
            if (count($items) >= $limit) {
                break;
            }

            $title = trim((string) ($item->title ?? ''));
            $link = trim((string) ($item->link ?? ''));
            if ($title === '' || $link === '') {
                continue;
            }

            $ts = $this->dateToTimestamp((string) ($item->pubDate ?? $item->published ?? ''));

            $items[] = [
                'title' => $title,
                'url' => $link,
                'date' => $ts,
                'date_label' => $ts !== null ? $this->humanDate($ts) : null,
            ];
        }

        return $items;
    }

    /**
     * Scraping de la page news de teamfortress.tv. La page ne fournit qu'une
     * date "Mon dd" sans année : on reconstruit une date absolue pour pouvoir
     * trier toutes les sources ensemble (voir inferTftvDate).
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamfortressTv(int $limit): array
    {
        $raw = JsonClient::getRaw('https://www.teamfortress.tv/news', 15, 'Mozilla/5.0 (X11; Linux x86_64)');
        if ($raw['http_code'] !== 200 || $raw['body'] === null || $raw['body'] === '') {
            return [];
        }

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$raw['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $xp = new \DOMXPath($dom);
        $rows = $xp->query('//table[contains(@class,"list-table")]/tr');
        if ($rows === false) {
            return [];
        }

        $items = [];
        $previousTs = null;
        foreach ($rows as $row) {
            if (count($items) >= $limit) {
                break;
            }

            if (! $row instanceof \DOMElement || $row->getElementsByTagName('th')->length > 0) {
                continue; // ligne d'en-tête
            }

            $cells = $xp->query('./td', $row);
            if ($cells === false || $cells->length < 3) {
                continue;
            }

            $anchor = $cells->item(0)?->getElementsByTagName('a')->item(0);
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $title = trim(html_entity_decode((string) $anchor->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $href = trim((string) $anchor->getAttribute('href'));
            if ($title === '' || $href === '') {
                continue;
            }

            $ts = $this->inferTftvDate(trim((string) $cells->item(2)?->textContent), $previousTs);
            if ($ts !== null) {
                $previousTs = $ts;
            }

            $items[] = [
                'title' => $title,
                'url' => 'https://www.teamfortress.tv'.$href,
                'date' => $ts,
                'date_label' => $ts !== null ? $this->humanDate($ts) : null,
            ];
        }

        return $items;
    }

    /**
     * Reconstruit une date absolue à partir d'un "Mon dd" sans année.
     * La liste est classée du plus récent au plus ancien : on part de l'année
     * courante (reculée d'un an si la date est encore à venir) puis on décrémente
     * l'année à chaque franchissement de frontière d'année.
     */
    private function inferTftvDate(string $raw, ?int $previousTs): ?int
    {
        $val = trim($raw);
        if ($val === '') {
            return null;
        }

        $candidate = strtotime($val);
        if ($candidate === false) {
            return null;
        }
        $candidate = (int) $candidate;

        // Premier élément : si la date tombe dans le futur cette année, c'est l'an dernier.
        if ($previousTs === null && $candidate > time()) {
            $rollback = strtotime('-1 year', $candidate);
            if ($rollback !== false) {
                $candidate = (int) $rollback;
            }
        }

        // Éléments suivants : la liste doit être strictement décroissante.
        $guard = 0;
        while ($previousTs !== null && $candidate >= $previousTs && $guard < 4) {
            $rollback = strtotime('-1 year', $candidate);
            if ($rollback === false) {
                break;
            }
            $candidate = (int) $rollback;
            $guard++;
        }

        return $candidate;
    }

    private function dateToTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts !== false ? $ts : null;
    }

    private function humanDate(int $ts): string
    {
        return date('d/m', $ts);
    }
}
