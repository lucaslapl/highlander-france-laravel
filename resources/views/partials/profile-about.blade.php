@php
    $aboutGear = [];
    foreach (($profileGear ?? []) as $field => $meta) {
        if (!empty($player[$field])) {
            $aboutGear[] = ['label' => $meta['label'], 'icon' => $meta['icon'], 'value' => $player[$field]];
        }
    }
@endphp

@if ($age !== null || count($aboutGear) > 0)
    <div class="profile-about">
        <h3>À propos</h3>

        <div class="box-stats profile-about__box">
            @if ($age !== null)
                <p class="flex align-center gap-10">
                    <i class="fa-solid fa-cake-candles"></i>
                    <span><strong>{!! e($age) !!}</strong> ans</span>
                </p>
            @endif

            @foreach ($aboutGear as $item)
                <p class="flex align-center gap-10">
                    <i class="{!! e($item['icon']) !!}"></i>
                    <span>{{ $item['label'] }} : <strong>{!! e($item['value']) !!}</strong></span>
                </p>
            @endforeach
        </div>
    </div>
@endif
