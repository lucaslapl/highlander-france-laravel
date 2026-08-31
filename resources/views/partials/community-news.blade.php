<div class="sidebar-card community-news">
    <div class="sidebar-card-header">
        <h3><i class="fa-solid fa-newspaper"></i> Actus communautaires</h3>
    </div>

    @if (empty($communityNews))
        <div class="sidebar-card-empty">
            <p><i class="fa-solid fa-circle-info"></i> Les actus sont momentanément indisponibles.</p>
        </div>
    @else
        <ul class="news-list">
            @foreach ($communityNews as $item)
                <li>
                    <a href="{{ e($item['url']) }}" target="_blank" rel="noopener noreferrer" class="news-item" title="{{ e($item['source_label']) }}">
                        <img loading="lazy" decoding="async" src="{{ e($item['logo']) }}" alt="{{ e($item['source_label']) }}" class="news-source-logo">
                        <span class="news-item-date">{{ $item['date_label'] ?? '' }}</span>
                        <span class="news-item-title">{{ $item['title'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>