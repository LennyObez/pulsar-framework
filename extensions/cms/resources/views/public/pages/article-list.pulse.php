@extends('public.layouts.public')

@section('title', ($categoryName ?? 'Articles') . ' - ' . ($siteName ?? 'Pulsar CMS'))

@section('meta_description', 'Browse our latest articles and insights.')

@section('content')
<div class="cms-listing">
    <header class="cms-listing__header">
        <h1 class="cms-listing__title">{{ $pageTitle ?? 'Articles' }}</h1>
        @if (!empty($pageDescription))
            <p class="cms-listing__description">{{ $pageDescription }}</p>
        @endif
    </header>

    {{-- Category filter --}}
    @if (!empty($categories))
        <nav class="cms-filter-bar" aria-label="Filter by category">
            <ul class="cms-filter-bar__list">
                <li>
                    <a href="/articles"
                       class="cms-filter-bar__item {{ empty($activeCategory) ? 'cms-filter-bar__item--active' : '' }}"
                       @if (empty($activeCategory)) aria-current="true" @endif>
                        All
                    </a>
                </li>
                @foreach ($categories as $category)
                    <?php /** @var array{slug: string, name: string, count: int} $category */ ?>
                    <li>
                        <a href="/category/{{ $category['slug'] }}"
                           class="cms-filter-bar__item {{ ($activeCategory ?? '') === $category['slug'] ? 'cms-filter-bar__item--active' : '' }}"
                           @if (($activeCategory ?? '') === $category['slug']) aria-current="true" @endif>
                            {{ $category['name'] }}
                            <span class="cms-filter-bar__count">{{ $category['count'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    {{-- Article cards --}}
    @if (!empty($articles))
        <div class="cms-article-grid cms-article-grid--listing">
            @foreach ($articles as $article)
                <?php /** @var array{id: string, title: string, slug: string, excerpt: string, published_at: string, author_name: string, type: string, reading_time_minutes?: int, category_name?: string, category_slug?: string} $article */ ?>
                <article class="cms-article-card">
                    <div class="cms-article-card__body">
                        @if (!empty($article['category_name']))
                            <a href="/category/{{ $article['category_slug'] ?? '' }}" class="cms-article-card__category">
                                {{ $article['category_name'] }}
                            </a>
                        @endif
                        <h2 class="cms-article-card__title">
                            <a href="/articles/{{ $article['slug'] }}">{{ $article['title'] }}</a>
                        </h2>
                        <p class="cms-article-card__excerpt">{{ $article['excerpt'] ?? '' }}</p>
                        <footer class="cms-article-card__meta">
                            @if (!empty($article['author_name']))
                                <span class="cms-article-card__author">{{ $article['author_name'] }}</span>
                                <span class="cms-article-card__separator" aria-hidden="true">&middot;</span>
                            @endif
                            @if (!empty($article['published_at']))
                                <time class="cms-article-card__date" datetime="{{ $article['published_at'] }}">
                                    {{ date('M j, Y', strtotime($article['published_at'])) }}
                                </time>
                            @endif
                            @if (!empty($article['reading_time_minutes']))
                                <span class="cms-article-card__separator" aria-hidden="true">&middot;</span>
                                <span class="cms-article-card__reading-time">{{ $article['reading_time_minutes'] }} min read</span>
                            @endif
                        </footer>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="cms-empty-state">
            <p class="cms-empty-state__message">No articles found.</p>
            <a href="/articles" class="pui-btn pui-btn--secondary">View all articles</a>
        </div>
    @endif

    {{-- Pagination --}}
    @if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1)
        <nav class="cms-pagination" aria-label="Article pagination">
            <ul class="cms-pagination__list">
                @if (($pagination['currentPage'] ?? 1) > 1)
                    <li>
                        <a href="{{ $pagination['baseUrl'] ?? '/articles' }}?page={{ ($pagination['currentPage'] ?? 1) - 1 }}"
                           class="cms-pagination__link cms-pagination__link--prev"
                           aria-label="Previous page">
                            Previous
                        </a>
                    </li>
                @endif

                @for ($p = 1; $p <= ($pagination['totalPages'] ?? 1); $p++)
                    <li>
                        @if ($p === ($pagination['currentPage'] ?? 1))
                            <span class="cms-pagination__link cms-pagination__link--current" aria-current="page">{{ $p }}</span>
                        @else
                            <a href="{{ $pagination['baseUrl'] ?? '/articles' }}?page={{ $p }}"
                               class="cms-pagination__link"
                               aria-label="Page {{ $p }}">
                                {{ $p }}
                            </a>
                        @endif
                    </li>
                @endfor

                @if (($pagination['currentPage'] ?? 1) < ($pagination['totalPages'] ?? 1))
                    <li>
                        <a href="{{ $pagination['baseUrl'] ?? '/articles' }}?page={{ ($pagination['currentPage'] ?? 1) + 1 }}"
                           class="cms-pagination__link cms-pagination__link--next"
                           aria-label="Next page">
                            Next
                        </a>
                    </li>
                @endif
            </ul>
        </nav>
    @endif
</div>
@endsection
