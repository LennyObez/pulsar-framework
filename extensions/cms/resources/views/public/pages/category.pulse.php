@extends('public.layouts.public')

@section('title', ($category['name'] ?? 'Category') . ' - ' . ($siteName ?? 'Pulsar CMS'))

@section('meta_description', $category['description'] ?? 'Browse articles in the ' . ($category['name'] ?? '') . ' category.')

@section('content')
<div class="cms-listing">
    {{-- Breadcrumbs --}}
    <nav class="cms-breadcrumbs" aria-label="Breadcrumb">
        <ol class="cms-breadcrumbs__list">
            <li class="cms-breadcrumbs__item">
                <a href="/">Home</a>
                <span class="cms-breadcrumbs__separator" aria-hidden="true">/</span>
            </li>
            <li class="cms-breadcrumbs__item">
                <a href="/articles">Articles</a>
                <span class="cms-breadcrumbs__separator" aria-hidden="true">/</span>
            </li>
            <li class="cms-breadcrumbs__item">
                <span aria-current="page">{{ $category['name'] ?? '' }}</span>
            </li>
        </ol>
    </nav>

    <header class="cms-listing__header">
        <h1 class="cms-listing__title">{{ $category['name'] ?? 'Category' }}</h1>
        @if (!empty($category['description']))
            <p class="cms-listing__description">{{ $category['description'] }}</p>
        @endif
        @if (isset($total))
            <p class="cms-listing__count">{{ $total }} article{{ $total !== 1 ? 's' : '' }}</p>
        @endif
    </header>

    {{-- Sub-categories --}}
    @if (!empty($subcategories))
        <nav class="cms-filter-bar" aria-label="Sub-categories">
            <ul class="cms-filter-bar__list">
                @foreach ($subcategories as $sub)
                    <?php /** @var array{slug: string, name: string, count: int} $sub */ ?>
                    <li>
                        <a href="/category/{{ $sub['slug'] }}" class="cms-filter-bar__item">
                            {{ $sub['name'] }}
                            <span class="cms-filter-bar__count">{{ $sub['count'] }}</span>
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
                <?php /** @var array{id: string, title: string, slug: string, excerpt: string, published_at: string, author_name: string, reading_time_minutes?: int} $article */ ?>
                <article class="cms-article-card">
                    <div class="cms-article-card__body">
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
            <p class="cms-empty-state__message">No articles in this category yet.</p>
            <a href="/articles" class="pui-btn pui-btn--secondary">View all articles</a>
        </div>
    @endif

    {{-- Pagination --}}
    @if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1)
        <nav class="cms-pagination" aria-label="Category pagination">
            <ul class="cms-pagination__list">
                @if (($pagination['currentPage'] ?? 1) > 1)
                    <li>
                        <a href="/category/{{ $category['slug'] ?? '' }}?page={{ ($pagination['currentPage'] ?? 1) - 1 }}"
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
                            <a href="/category/{{ $category['slug'] ?? '' }}?page={{ $p }}"
                               class="cms-pagination__link"
                               aria-label="Page {{ $p }}">
                                {{ $p }}
                            </a>
                        @endif
                    </li>
                @endfor

                @if (($pagination['currentPage'] ?? 1) < ($pagination['totalPages'] ?? 1))
                    <li>
                        <a href="/category/{{ $category['slug'] ?? '' }}?page={{ ($pagination['currentPage'] ?? 1) + 1 }}"
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
