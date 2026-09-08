@php
$sprite = \App\Services\CountryFlags::spriteClass($country ?? null);
$url = $sprite === null ? \App\Services\CountryFlags::flag($country ?? null) : null;
$label = $label ?? (string) ($country ?? '');
$css = 'team-flag ' . ($class ?? '');
@endphp
@if ($sprite !== null)
    <span class="hlfr-sprite {{ $sprite }} {{ trim($css) }}" role="img" aria-label="{{ e($label) }}" title="{{ e($label) }}"></span>
@else
    <img loading="lazy" decoding="async" src="{{ e($url) }}" alt="{{ e($label) }}" class="{{ trim($css) }}" title="{{ e($label) }}" width="20" height="13">
@endif
