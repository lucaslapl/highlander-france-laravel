<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Import des miniatures de maps depuis https://etf2l.org/maps/.
 *
 * Le scrap récupère la page, en extrait les couples (map → image) et
 * télécharge les miniatures dans storage/public/etf2l-maps/thumbnails/.
 * Les maps absentes de la page sont simplement laissées sans vignette.
 */
final class Etf2lThumbnailsImporter
{
    private const PAGE_URL = 'https://etf2l.org/maps/';

    private const THUMB_FOLDER = 'etf2l-maps/thumbnails';

    /**
     * Import des miniatures pour toutes les maps en base.
     *
     * @return array{matched_exact: list<string>, matched_base: list<string>, skipped: list<string>}
     */
    public function importAll(bool $force = false): array
    {
        $pageImages = $this->fetchPageImages();

        $baseIndex = [];
        foreach ($pageImages as $name => $url) {
            $baseIndex[$this->baseName($name)][$name] = $url;
        }

        $result = ['matched_exact' => [], 'matched_base' => [], 'skipped' => []];
        $thumbDir = storage_path('app/public/' . self::THUMB_FOLDER);

        foreach ($this->allMaps() as $map) {
            $name = (string) $map['name'];

            if (isset($pageImages[$name])) {
                $url = $pageImages[$name];
                $stat = 'matched_exact';
            } else {
                $candidates = $baseIndex[$this->baseName($name)] ?? [];
                if ($candidates === []) {
                    $result['skipped'][] = $name;
                    continue;
                }
                $url = array_values($candidates)[0];
                $stat = 'matched_base';
            }

            $extension = $this->extensionFromUrl($url);
            if ($extension === null) {
                $result['skipped'][] = $name;
                continue;
            }

            $thumbRel = self::THUMB_FOLDER . '/' . $name . '.' . $extension;
            $thumbAbs = $thumbDir . '/' . $name . '.' . $extension;
            $already = $map['thumbnail'] === $thumbRel && is_file($thumbAbs);

            if (! $already || $force) {
                if (! $this->download($url, $thumbAbs)) {
                    $result['skipped'][] = $name;
                    continue;
                }
            }

            DB::table('etf2l_maps')->where('id', (int) $map['id'])->update([
                'thumbnail' => $thumbRel,
                'updated_at' => now(),
            ]);
            $result[$stat][] = $name;
        }

        return $result;
    }

    /**
     * @return list<array{id: int, name: string, thumbnail: string|null}>
     */
    private function allMaps(): array
    {
        return DB::table('etf2l_maps')
            ->select('id', 'name', 'thumbnail')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Extraction des couples map → image depuis la page ETF2L.
     *
     * @return array<string, string>  clé = nom exact de la map, valeur = URL de l'image
     */
    private function fetchPageImages(): array
    {
        $response = JsonClient::getRaw(self::PAGE_URL, 30);
        if ($response['body'] === null || $response['http_code'] !== 200) {
            throw new \RuntimeException(
                'Impossible de récupérer ' . self::PAGE_URL
                . ' (HTTP ' . $response['http_code'] . ($response['curl_error'] !== '' ? ', ' . $response['curl_error'] : '') . ')'
            );
        }

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $response['body']);
        libxml_clear_errors();
        if ($loaded === false) {
            throw new \RuntimeException('HTML de la page ETF2L illisible');
        }

        $xpath = new \DOMXPath($document);
        $images = [];

        // Pour chaque lien de téléchargement .bsp, on cherche la ligne <tr> suivante
        // (miniature à la même colonne) et on extrait la première <img> wp-content.
        $links = $xpath->query('//a[contains(@href, "dl.serveme.tf/maps/") and substring(@href, string-length(@href) - 3) = ".bsp"]');
        if ($links === false) {
            return $images;
        }

        foreach ($links as $link) {
            $href = (string) $link->getAttribute('href');
            $name = basename($href, '.bsp');
            if ($name === '') {
                continue;
            }

            $td = $this->closest($link, 'td');
            $tr = $td !== null ? $this->closest($td, 'tr') : null;
            if ($tr === null || $td === null) {
                continue;
            }

            $column = 0;
            foreach ($tr->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->tagName === 'td') {
                    if ($child->isSameNode($td)) {
                        break;
                    }
                    ++$column;
                }
            }

            $imageTr = $tr->nextSibling;
            while ($imageTr !== null && ! ($imageTr instanceof \DOMElement && $imageTr->tagName === 'tr')) {
                $imageTr = $imageTr->nextSibling;
            }
            if ($imageTr === null) {
                continue;
            }

            $imageUrl = $this->imageUrlAtColumn($imageTr, $column);
            if ($imageUrl === null) {
                continue;
            }

            $images[$name] = $imageUrl;
        }

        return $images;
    }

    /**
     * URL de la première image wp-content trouvée dans la <td> d'indice $column.
     */
    private function imageUrlAtColumn(\DOMElement $tr, int $column): ?string
    {
        $index = 0;
        foreach ($tr->childNodes as $child) {
            if (! ($child instanceof \DOMElement) || $child->tagName !== 'td') {
                continue;
            }
            if ($index !== $column) {
                ++$index;
                continue;
            }

            $imgs = $child->getElementsByTagName('img');
            foreach ($imgs as $img) {
                $src = (string) $img->getAttribute('src');
                if ($src !== '' && str_contains($src, 'wp-content/uploads')) {
                    return $src;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Ancêtre le plus proche portant le tag demandé.
     */
    private function closest(\DOMNode $node, string $tag): ?\DOMElement
    {
        $current = $node->parentNode;
        while ($current instanceof \DOMElement) {
            if ($current->tagName === $tag) {
                return $current;
            }
            $current = $current->parentNode;
        }

        return null;
    }

    /**
     * Nom de base d'une map en retirant le suffixe de version
     * (ex: cp_metalworks_f7 → cp_metalworks, cp_granary_pro_rc17a3 → cp_granary_pro,
     * cp_sunshine → cp_sunshine).
     */
    private function baseName(string $name): string
    {
        return (string) preg_replace(
            '/_(?:final\d*|(?:rc|b|f|v|a)\d+[a-z0-9]*|beta\d*)$/',
            '',
            strtolower($name)
        );
    }

    private function extensionFromUrl(string $url): ?string
    {
        $extension = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return null;
    }

    private function download(string $url, string $destination): bool
    {
        $response = JsonClient::getRaw($url, 60);
        if ($response['body'] === null || $response['http_code'] !== 200 || $response['body'] === '') {
            return false;
        }

        $directory = dirname($destination);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        return @file_put_contents($destination, $response['body']) !== false;
    }
}