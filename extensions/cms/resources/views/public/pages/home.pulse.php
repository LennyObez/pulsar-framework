@extends('public.layouts.public')

@section('title', ($translation !== null ? ($translation->metaTitle ?? $translation->title) : null) ?? $siteName ?? 'Home')

@section('meta_description', $translation->metaDescription ?? $siteDescription ?? 'Welcome to our site.')

@section('meta')
    @if (!empty($hreflang))
        @foreach ($hreflang as $link)
            <link rel="alternate" hreflang="{{ $link['locale'] }}" href="{{ $link['href'] }}">
        @endforeach
    @endif
    @if (!empty($jsonLd))
        {!! $jsonLd !!}
    @endif
    <link rel="canonical" href="{{ $canonicalUrl ?? '' }}">
@endsection

@section('hero')
@if (!empty($renderedBlocks))
    {{-- Hero and other blocks are rendered from the CMS block editor --}}
@else
    <section class="cms-hero" aria-labelledby="hero-heading">
        <div class="cms-hero__inner">
            <h1 id="hero-heading" class="cms-hero__title">{{ $translation->title ?? $siteName ?? 'Welcome' }}</h1>
            @if ($translation !== null && $translation->excerpt !== null)
                <p class="cms-hero__subtitle">{{ $translation->excerpt }}</p>
            @endif
        </div>
    </section>
@endif
@endsection

@section('content')
<div class="cms-home">
    {{-- Rendered CMS blocks (hero, feature-grid, cta, etc.) --}}
    @if (!empty($renderedBlocks))
        <div class="cms-home__blocks pui-prose">
            {!! $renderedBlocks !!}
        </div>
    @elseif ($translation !== null && !empty($translation->body))
        <div class="cms-home__body pui-prose">
            {!! $translation->body !!}
        </div>
    @endif

    {{-- Featured content --}}
    @if (!empty($featured))
        <section class="cms-home__featured" aria-labelledby="featured-heading">
            <h2 id="featured-heading" class="cms-section-heading">Featured</h2>
            <div class="cms-featured-grid">
                @foreach ($featured as $index => $item)
                    <?php /** @var array{id: string, title: string, slug: string, excerpt: string, published_at: string, author_name: string, type: string, reading_time_minutes?: int} $item */ ?>
                    <article class="cms-featured-card {{ $index === 0 ? 'cms-featured-card--primary' : '' }}">
                        <div class="cms-featured-card__body">
                            <span class="cms-featured-card__category">{{ ucfirst($item['type'] ?? 'article') }}</span>
                            <h3 class="cms-featured-card__title">
                                <a href="/articles/{{ $item['slug'] }}">{{ $item['title'] }}</a>
                            </h3>
                            <p class="cms-featured-card__excerpt">{{ $item['excerpt'] ?? '' }}</p>
                            <div class="cms-featured-card__meta">
                                <span class="cms-featured-card__author">{{ $item['author_name'] ?? '' }}</span>
                                @if (!empty($item['published_at']))
                                    <time class="cms-featured-card__date" datetime="{{ $item['published_at'] }}">
                                        {{ date('M j, Y', strtotime($item['published_at'])) }}
                                    </time>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Recent articles --}}
    @if (!empty($recentArticles))
        <section class="cms-home__recent" aria-labelledby="recent-heading">
            <div class="cms-home__recent-header">
                <h2 id="recent-heading" class="cms-section-heading">Recent articles</h2>
                <a href="/articles" class="cms-home__view-all">View all</a>
            </div>
            <div class="cms-article-grid">
                @foreach ($recentArticles as $article)
                    <?php /** @var array{id: string, title: string, slug: string, excerpt: string, published_at: string, author_name: string, reading_time_minutes?: int} $article */ ?>
                    <article class="cms-article-card">
                        <div class="cms-article-card__body">
                            <h3 class="cms-article-card__title">
                                <a href="/articles/{{ $article['slug'] }}">{{ $article['title'] }}</a>
                            </h3>
                            <p class="cms-article-card__excerpt">{{ $article['excerpt'] ?? '' }}</p>
                            <footer class="cms-article-card__meta">
                                <span class="cms-article-card__author">{{ $article['author_name'] ?? '' }}</span>
                                @if (!empty($article['published_at']))
                                    <span class="cms-article-card__separator" aria-hidden="true">&middot;</span>
                                    <time class="cms-article-card__date" datetime="{{ $article['published_at'] }}">
                                        {{ date('M j, Y', strtotime($article['published_at'])) }}
                                    </time>
                                @endif
                            </footer>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Category navigation --}}
    @if (!empty($categories))
        <section class="cms-home__categories" aria-labelledby="categories-heading">
            <h2 id="categories-heading" class="cms-section-heading">Categories</h2>
            <nav class="cms-category-nav" aria-label="Content categories">
                <ul class="cms-category-nav__list">
                    @foreach ($categories as $category)
                        <?php /** @var array{slug: string, name: string, count: int} $category */ ?>
                        <li class="cms-category-nav__item">
                            <a href="/category/{{ $category['slug'] }}" class="cms-category-nav__link">
                                <span class="cms-category-nav__name">{{ $category['name'] }}</span>
                                <span class="cms-category-nav__count" aria-label="{{ $category['count'] }} items">{{ $category['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </section>
    @endif
</div>
@endsection
