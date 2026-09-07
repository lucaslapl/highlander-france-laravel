<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Rendu du Markdown étendu des guides / FAQ.
 *
 * Syntaxes supportées en plus du Markdown standard :
 * - tableaux GFM
 * - attributs `{.classe}` via AttributesExtension
 * - blocs conteneurs `:::info|conseil|danger|combo|flank` ... `:::`
 * - HTML inline restreint (`span`, `a`) pour les couleurs
 */
final class GuideMarkdown
{
    private const CONTAINERS = [
        'info' => 'guide-callout guide-callout--info',
        'conseil' => 'guide-callout guide-callout--tip',
        'danger' => 'guide-callout guide-callout--danger',
        'combo' => 'guide-callout guide-callout--combo',
        'flank' => 'guide-callout guide-callout--flank',
    ];

    private MarkdownConverter $converter;

    public function __construct()
    {
        $env = new Environment([
            'external_link' => [
                'open_in_new_window' => true,
            ],
            'heading_permalink' => [
                'html_class' => 'guide-anchor',
                'symbol' => '#',
            ],
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $env->addExtension(new AttributesExtension());
        $env->addExtension(new ExternalLinkExtension());
        $env->addExtension(new HeadingPermalinkExtension());

        $this->converter = new MarkdownConverter($env);
    }

    public function toHtml(string $markdown): string
    {
        $html = $this->convertWithContainers($markdown);

        return $this->sanitize($html);
    }

    /**
     * `:::type [titre]` ... `:::` → `<div class="...">` avec contenu Markdown converti.
     */
    private function convertWithContainers(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $out = [];
        $buffer = [];
        $container = null;
        $title = '';

        $flush = function () use (&$out, &$buffer): void {
            if ($buffer !== []) {
                $out[] = (string) $this->converter->convert(implode("\n", $buffer));
                $buffer = [];
            }
        };

        foreach ($lines as $line) {
            if ($container === null && preg_match('/^:::\s*([a-z]+)(?:\s+(.*))?$/i', trim($line), $m) && isset(self::CONTAINERS[strtolower($m[1])])) {
                $flush();
                $container = strtolower($m[1]);
                $title = trim($m[2] ?? '');
                $buffer = [];
                continue;
            }
            if ($container !== null && trim($line) === ':::') {
                $inner = (string) $this->converter->convert(implode("\n", $buffer));
                $titleHtml = $title !== '' ? '<p class="guide-callout__title">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</p>' : '';
                $out[] = '<div class="'.self::CONTAINERS[$container].'">'.$titleHtml.$inner.'</div>';
                $container = null;
                $title = '';
                $buffer = [];
                continue;
            }
            $buffer[] = $line;
        }

        if ($container !== null) {
            $inner = (string) $this->converter->convert(implode("\n", $buffer));
            $titleHtml = $title !== '' ? '<p class="guide-callout__title">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</p>' : '';
            $out[] = '<div class="'.self::CONTAINERS[$container].'">'.$titleHtml.$inner.'</div>';
        } else {
            $flush();
        }

        return implode("\n", $out);
    }

    /**
     * Nettoyage HTML : balises de mise en forme + div/span avec classes
     * whitelistées uniquement (couleurs et callouts).
     */
    private function sanitize(string $html): string
    {
        $allowedClasses = [
            'guide-callout', 'guide-callout--info', 'guide-callout--tip', 'guide-callout--danger',
            'guide-callout--combo', 'guide-callout--flank', 'guide-callout__title',
            'guide-anchor', 'hl-blue', 'hl-red', 'hl-green', 'hl-gold',
        ];

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="hlfr-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $allowed = ['a', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'blockquote', 'hr', 'br', 'div', 'span'];
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*') as $node) {
            /** @var \DOMElement $node */
            if ($node->getAttribute('id') === 'hlfr-root' || $node->tagName === 'html' || $node->tagName === 'body') {
                continue;
            }
            if (! in_array(strtolower($node->tagName), $allowed, true)) {
                $node->parentNode?->replaceChild($doc->createTextNode($node->textContent), $node);
                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                if ($name === 'class') {
                    $classes = array_values(array_intersect(preg_split('/\s+/', $attr->nodeValue) ?: [], $allowedClasses));
                    if ($classes === []) {
                        $node->removeAttribute('class');
                    } else {
                        $node->setAttribute('class', implode(' ', $classes));
                    }
                    continue;
                }
                if ($node->tagName === 'a' && in_array($name, ['href', 'title'], true)) {
                    $href = (string) $node->getAttribute('href');
                    if ($name === 'href' && ! preg_match('~^(https?://|/|#)~i', $href)) {
                        $node->removeAttribute('href');
                    }
                    continue;
                }
                if ($node->tagName === 'a' && in_array($name, ['target', 'rel'], true)) {
                    continue;
                }
                $node->removeAttribute($attr->nodeName);
            }
        }

        $root = $doc->getElementById('hlfr-root');

        return $root !== null ? $doc->saveHTML($root) : $html;
    }
}
