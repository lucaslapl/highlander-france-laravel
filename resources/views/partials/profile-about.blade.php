@php
    $aboutLinks = [];
    foreach (($profileLinks ?? []) as $field => $meta) {
        if (!empty($player[$field])) {
            $aboutLinks[] = ['meta' => $meta, 'value' => $player[$field]];
        }
    }

    $aboutGear = [];
    foreach (($profileGear ?? []) as $field => $meta) {
        if (!empty($player[$field])) {
            $aboutGear[] = ['label' => $meta['label'], 'icon' => $meta['icon'], 'value' => $player[$field]];
        }
    }
@endphp

<div class="profile-cards-row">

        <div class="profile-info-card">
            <h4><i class="fa-solid fa-link"></i> Liens</h4>
            <div class="flex align-center gap-10" style="flex-wrap: wrap;">
                <a href="https://steamcommunity.com/profiles/{!! e($steamid64) !!}" target="_blank" rel="noopener noreferrer"
                   class="profile-link-icon" title="Profil Steam" aria-label="Profil Steam">
                    <i class="fa-brands fa-steam"></i>
                </a>
                @foreach ($aboutLinks as $link)
                    @if ($link['meta']['type'] === 'url')
                        <a href="{!! e($link['value']) !!}" target="_blank" rel="noopener noreferrer"
                           class="profile-link-icon" title="{{ $link['meta']['label'] }}" aria-label="{{ $link['meta']['label'] }}">
                            <i class="{!! e($link['meta']['icon']) !!}"></i>
                        </a>
                    @else
                        <span class="profile-link-icon profile-link-icon--tag"
                              title="{{ $link['meta']['label'] }} : {!! e($link['value']) !!}">
                            <i class="{!! e($link['meta']['icon']) !!}"></i>
                        </span>
                    @endif
                @endforeach
            </div>
        </div>

        @if ($age !== null)
            <div class="profile-info-card">
                <h4><i class="fa-solid fa-id-card"></i> Infos</h4>
                <p class="flex align-center gap-10">
                    <i class="fa-solid fa-cake-candles"></i>
                    <span><strong>{!! e($age) !!}</strong> ans</span>
                </p>
            </div>
        @endif

        @if (count($aboutGear) > 0)
            <div class="profile-info-card">
                <h4><i class="fa-solid fa-computer"></i> Matériel</h4>
                @foreach ($aboutGear as $item)
                    <p class="flex align-center gap-10">
                        <i class="{!! e($item['icon']) !!}"></i>
                        <span>{{ $item['label'] }} : <strong>{!! e($item['value']) !!}</strong></span>
                    </p>
                @endforeach
            </div>
        @endif

    </div>
</div>
