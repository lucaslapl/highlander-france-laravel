{{-- Vignettes « équipes déjà castées » : application en un clic du nom et de
     l'URL d'avatar aux champs {team}_name / {team}_avatar_url du formulaire
     courant (admin_overlay.js). Partagé par les outils Overlay Logs et
     Overlay Scores ; la mémoire vit dans overlays/avatar_urls.json.
     Variables attendues : $team (red|blue), $label (A|B), $memorizedAvatars. --}}
@if (! empty($memorizedAvatars))
    <div class="overlay-avatar-memory" data-team="{{ $team }}" style="margin-top:10px;">
        <span class="overlay-avatar-memory__label">Équipes déjà castées — un clic applique nom + avatar :</span>
        <div class="overlay-avatar-memory__tiles">
            @foreach ($memorizedAvatars as $entry)
                <button type="button" class="overlay-avatar-memory__tile"
                        data-name="{{ e($entry['name']) }}" data-url="{{ e($entry['url']) }}"
                        title="Appliquer « {{ e($entry['name'] !== '' ? $entry['name'] : $entry['url']) }} » à l'équipe {{ $label }}">
                    <img src="{{ e($entry['url']) }}" alt="" loading="lazy">
                    <span>{{ e($entry['name'] !== '' ? $entry['name'] : '(sans nom)') }}</span>
                </button>
            @endforeach
        </div>
    </div>
@endif
