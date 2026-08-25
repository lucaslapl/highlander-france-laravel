<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Résolution des drapeaux de pays.
 *
 * Accepte aussi bien un nom de pays complet (ex. "Belgium", "United States",
 * tel que renvoyé par l'API ETF2L) qu'un code ISO 3166-1 alpha-2 ("be", "us").
 *
 * Règle de résolution :
 *  1. si l'entrée correspond à un asset local existant (_img/flags/{code}.gif),
 *     on l'utilise (cas du site : eu, breizh, fr, mo, tu, sw, unknown…) ;
 *  2. sinon, drapeau ISO via le CDN flagcdn.com (couverture complète) ;
 *  3. sinon, drapeau "unknown" local.
 */
final class CountryFlags
{
    private const FLAG_CDN = 'https://flagcdn.com/w40/%s.png';

    private const LOCAL_CODES = [
        'al', 'be', 'breizh', 'ca', 'eu', 'fr', 'lu', 'mo', 'sw', 'tu', 'uk', 'unknown',
    ];

    /** Codes locaux non-ISO du site (drapeaux customs). */
    private const CUSTOM_CODES = ['eu', 'breizh'];

    /** Préférences d'assets locaux pour certains codes ISO (cohérence visuelle). */
    private const LOCAL_PREFER = ['gb' => 'uk'];

    /**
     * Nom de pays -> code ISO 3166-1 alpha-2 (comparaison sur nom compacté).
     */
    private const COUNTRY_TO_ISO = [
        'afghanistan' => 'af', 'alandislands' => 'ax', 'albania' => 'al', 'algeria' => 'dz',
        'americansamoa' => 'as', 'andorra' => 'ad', 'angola' => 'ao', 'anguilla' => 'ai',
        'antarctica' => 'aq', 'antiguaandbarbuda' => 'ag', 'argentina' => 'ar', 'armenia' => 'am',
        'aruba' => 'aw', 'australia' => 'au', 'austria' => 'at', 'azerbaijan' => 'az',
        'bahamas' => 'bs', 'bahrain' => 'bh', 'bangladesh' => 'bd', 'barbados' => 'bb',
        'belarus' => 'by', 'belgium' => 'be', 'belize' => 'bz', 'benin' => 'bj',
        'bermuda' => 'bm', 'bhutan' => 'bt', 'bolivia' => 'bo', 'bosniaandherzegovina' => 'ba',
        'botswana' => 'bw', 'brazil' => 'br', 'britishindianoceanterritory' => 'io',
        'brunei' => 'bn', 'bulgaria' => 'bg', 'burkinafaso' => 'bf', 'burundi' => 'bi',
        'cambodia' => 'kh', 'cameroon' => 'cm', 'canada' => 'ca', 'capeverde' => 'cv',
        'caymanislands' => 'ky', 'centralafricanrepublic' => 'cf', 'chad' => 'td',
        'chile' => 'cl', 'china' => 'cn', 'colombia' => 'co', 'comoros' => 'km',
        'congo' => 'cg', 'democraticrepublicofthecongo' => 'cd', 'cookislands' => 'ck',
        'costarica' => 'cr', 'cotedivoire' => 'ci', 'croatia' => 'hr', 'cuba' => 'cu',
        'cyprus' => 'cy', 'czechia' => 'cz', 'czechrepublic' => 'cz', 'denmark' => 'dk',
        'djibouti' => 'dj', 'dominica' => 'dm', 'dominicanrepublic' => 'do',
        'easttimor' => 'tl', 'ecuador' => 'ec', 'egypt' => 'eg', 'elsalvador' => 'sv',
        'equatorialguinea' => 'gq', 'eritrea' => 'er', 'estonia' => 'ee', 'eswatini' => 'sz',
        'ethiopia' => 'et', 'falklandislands' => 'fk', 'faroeislands' => 'fo', 'fiji' => 'fj',
        'finland' => 'fi', 'france' => 'fr', 'frenchguiana' => 'gf', 'frenchpolynesia' => 'pf',
        'gabon' => 'ga', 'gambia' => 'gm', 'georgia' => 'ge', 'germany' => 'de',
        'ghana' => 'gh', 'gibraltar' => 'gi', 'greece' => 'gr', 'greenland' => 'gl',
        'grenada' => 'gd', 'guadeloupe' => 'gp', 'guam' => 'gu', 'guatemala' => 'gt',
        'guernsey' => 'gg', 'guinea' => 'gn', 'guineabissau' => 'gw', 'guyana' => 'gy',
        'haiti' => 'ht', 'honduras' => 'hn', 'hongkong' => 'hk', 'hungary' => 'hu',
        'iceland' => 'is', 'india' => 'in', 'indonesia' => 'id', 'iran' => 'ir',
        'iraq' => 'iq', 'ireland' => 'ie', 'isleofman' => 'im', 'israel' => 'il',
        'italy' => 'it', 'jamaica' => 'jm', 'japan' => 'jp', 'jersey' => 'je',
        'jordan' => 'jo', 'kazakhstan' => 'kz', 'kenya' => 'ke', 'kiribati' => 'ki',
        'kosovo' => 'xk', 'kuwait' => 'kw', 'kyrgyzstan' => 'kg', 'laos' => 'la',
        'latvia' => 'lv', 'lebanon' => 'lb', 'lesotho' => 'ls', 'liberia' => 'lr',
        'libya' => 'ly', 'liechtenstein' => 'li', 'lithuania' => 'lt', 'luxembourg' => 'lu',
        'macao' => 'mo', 'macau' => 'mo', 'madagascar' => 'mg', 'malawi' => 'mw',
        'malaysia' => 'my', 'maldives' => 'mv', 'mali' => 'ml', 'malta' => 'mt',
        'marshallislands' => 'mh', 'martinique' => 'mq', 'mauritania' => 'mr',
        'mauritius' => 'mu', 'mexico' => 'mx', 'micronesia' => 'fm', 'moldova' => 'md',
        'monaco' => 'mc', 'mongolia' => 'mn', 'montenegro' => 'me', 'morocco' => 'ma',
        'mozambique' => 'mz', 'myanmar' => 'mm', 'namibia' => 'na', 'nauru' => 'nr',
        'nepal' => 'np', 'netherlands' => 'nl', 'newcaledonia' => 'nc', 'newzealand' => 'nz',
        'nicaragua' => 'ni', 'niger' => 'ne', 'nigeria' => 'ng', 'niue' => 'nu',
        'northkorea' => 'kp', 'northmacedonia' => 'mk', 'northernmarianaislands' => 'mp',
        'norway' => 'no', 'oman' => 'om', 'pakistan' => 'pk', 'palau' => 'pw',
        'palestine' => 'ps', 'panama' => 'pa', 'papuanewguinea' => 'pg', 'paraguay' => 'py',
        'peru' => 'pe', 'philippines' => 'ph', 'poland' => 'pl', 'portugal' => 'pt',
        'puertorico' => 'pr', 'qatar' => 'qa', 'reunion' => 're', 'romania' => 'ro',
        'russia' => 'ru', 'russianfederation' => 'ru', 'rwanda' => 'rw',
        'saintbarthlemy' => 'bl', 'saintkittsandnevis' => 'kn', 'saintlucia' => 'lc',
        'saintmartin' => 'mf', 'saintpierreandmiquelon' => 'pm',
        'saintvincentandthegrenadines' => 'vc', 'samoa' => 'ws', 'sanmarino' => 'sm',
        'saotomeandprincipe' => 'st', 'saudiarabia' => 'sa', 'senegal' => 'sn',
        'serbia' => 'rs', 'seychelles' => 'sc', 'sierraleone' => 'sl', 'singapore' => 'sg',
        'stbarthelemy' => 'bl', 'slovakia' => 'sk', 'slovenia' => 'si', 'solomonislands' => 'sb',
        'somalia' => 'so', 'southafrica' => 'za', 'southkorea' => 'kr', 'southsudan' => 'ss',
        'spain' => 'es', 'srilanka' => 'lk', 'sudan' => 'sd', 'suriname' => 'sr',
        'swaziland' => 'sz', 'sweden' => 'se', 'switzerland' => 'ch', 'syria' => 'sy',
        'taiwan' => 'tw', 'tajikistan' => 'tj', 'tanzania' => 'tz', 'thailand' => 'th',
        'timorleste' => 'tl', 'togo' => 'tg', 'tonga' => 'to', 'trinidadandtobago' => 'tt',
        'tunisia' => 'tn', 'turkey' => 'tr', 'turkmenistan' => 'tm', 'turksandcaicosislands' => 'tc',
        'tuvalu' => 'tv', 'uganda' => 'ug', 'ukraine' => 'ua', 'unitedarabemirates' => 'ae',
        'unitedkingdom' => 'gb', 'unitedstates' => 'us', 'uruguay' => 'uy',
        'usvirginislands' => 'vi', 'uzbekistan' => 'uz', 'vanuatu' => 'vu', 'vatican' => 'va',
        'venezuela' => 've', 'vietnam' => 'vn', 'virginislands' => 'vi', 'yemen' => 'ye',
        'zambia' => 'zm', 'zimbabwe' => 'zw',
        // Variantes françaises courantes (profils du site + correspondances customs)
        'algérie' => 'dz', 'belgique' => 'be', 'canada' => 'ca', 'france' => 'fr',
        'luxembourg' => 'lu', 'maroc' => 'ma', 'royaume-uni' => 'gb', 'suisse' => 'ch',
        'tunisie' => 'tn', 'europe' => 'eu', 'bretagne' => 'breizh',
        // "International" (équipes/joueurs multi-nationalités) : drapeau ONU
        'international' => 'un', 'internationale' => 'un', 'inter' => 'un',
        'global' => 'un', 'world' => 'un', 'multinational' => 'un',
        'unitednations' => 'un',
    ];

    private const ISO_ALIAS = [
        'uk' => 'gb', 'england' => 'gb', 'scotland' => 'gb', 'wales' => 'gb',
        'northireland' => 'gb',
    ];

    /**
     * URL du drapeau pour un pays (nom complet ou code ISO).
     */
    public static function flag(?string $country): string
    {
        $raw = $country !== null ? strtolower(trim((string)$country)) : '';
        $raw = $raw === '' ? 'unknown' : $raw;

        if ($raw === 'unknown') {
            return '/_img/flags/unknown.gif';
        }

        $code = self::resolveCode($raw);

        // Asset local existant (drapeaux du site ou ISO déjà dispo en local).
        if ($code !== null && is_file(public_path('/_img/flags/' . $code . '.gif'))) {
            return '/_img/flags/' . $code . '.gif';
        }

        // Asset local préféré (ex. gb -> uk.gif, déjà présent sur le site).
        $preferred = isset(self::LOCAL_PREFER[$code ?? '']) ? self::LOCAL_PREFER[$code] : null;
        if ($preferred !== null && is_file(public_path() . '/_img/flags/' . $preferred . '.gif')) {
            return '/_img/flags/' . $preferred . '.gif';
        }

        // Drapeau ISO via CDN.
        if ($code !== null && preg_match('/^[a-z]{2}$/', $code)) {
            return sprintf(self::FLAG_CDN, $code);
        }

        return '/_img/flags/unknown.gif';
    }

    private static function resolveCode(string $raw): ?string
    {
        if (in_array($raw, self::CUSTOM_CODES, true)) {
            return $raw;
        }

        if (isset(self::COUNTRY_TO_ISO[$raw])) {
            return self::normalizeCode(self::COUNTRY_TO_ISO[$raw]);
        }

        $compact = preg_replace('/[\s\-\.]/', '', $raw);
        if ($compact !== $raw && isset(self::COUNTRY_TO_ISO[$compact])) {
            return self::normalizeCode(self::COUNTRY_TO_ISO[$compact]);
        }

        if (isset(self::ISO_ALIAS[$compact])) {
            return self::ISO_ALIAS[$compact];
        }

        if (preg_match('/^[a-z]{2}$/', $raw)) {
            return $raw; // code ISO fourni directement
        }

        return null;
    }

    private static function normalizeCode(string $code): string
    {
        return self::ISO_ALIAS[$code] ?? $code;
    }
}