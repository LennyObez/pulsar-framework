@extends('public.layouts.public')

@section('title', (!empty($query) ? 'Search: ' . $query : 'Search') . ' - ' . ($siteName ?? 'Pulsar CMS'))

@section('meta')
    <meta name="robots" content="noindex, follow">
@endsection

@section('content')
<div class="cms-search-page">
    <header class="cms-search-page__header">
        <h1 class="cms-search-page__title">Search</h1>

        <form class="cms-search-page__form" action="/search" method="GET" role="search" aria-label="Search content">
            <div class="cms-search-page__input-group">
                <label for="search-query" class="pui-sr-only">Search query</label>
                <input type="search"
                       id="search-query"
                       name="q"
                       value="{{ $query ?? '' }}"
                       class="cms-search-page__input"
                       placeholder="Search articles, pages, and more..."
                       autofocus
                       aria-describedby="search-hint">
                <button type="submit" class="pui-btn pui-btn--primary cms-search-page__submit">
                    Search
                </button>
            </div>
            <p id="search-hint" class="cms-search-page__hint">Enter at least 2 characters to search.</p>
        </form>
    </header>

    @if (!empty($query))
        <div class="cms-search-page__results" aria-live="polite">
            {{-- Results summary --}}
            <p class="cms-search-page__summary">
                @if (($total ?? 0) > 0)
                    {{ $total }} result{{ ($total ?? 0) !== 1 ? 's' : '' }} for <strong>&quot;{{ $query }}&quot;</strong>
                    @if (!empty($tookMs))
                        <span class="cms-search-page__timing">({{ number_format($tookMs, 1) }}ms)</span>
                    @endif
                @else
                    No results found for <strong>&quot;{{ $query }}&quot;</strong>
                @endif
            </p>

            {{-- Search results --}}
            @if (!empty($results))
                <ol class="cms-search-results">
                    @foreach ($results as $result)
                        <?php /** @var array{id: string, title: string, slug: string, excerpt: string, type: string, published_at: string, path: string} $result */ ?>
                        <li class="cms-search-result">
                            <article>
                                <span class="cms-search-result__type">{{ ucfirst($result['type'] ?? 'page') }}</span>
                                <h2 class="cms-search-result__title">
                                    <a href="{{ $result['path'] ?? '/articles/' . ($result['slug'] ?? '') }}">
                                        {{ $result['title'] ?? '' }}
                                    </a>
                                </h2>
                                @if (!empty($result['excerpt']))
                                    <p class="cms-search-result__excerpt">{{ $result['excerpt'] }}</p>
                                @endif
                                <div class="cms-search-result__meta">
                                    @if (!empty($result['published_at']))
                                        <time class="cms-search-result__date" datetime="{{ $result['published_at'] }}">
                                            {{ date('M j, Y', strtotime($result['published_at'])) }}
                                        </time>
                                    @endif
                                </div>
                            </article>
                        </li>
                    @endforeach
                </ol>
            @endif

            {{-- Suggestions --}}
            @if (empty($results) && !empty($suggestions))
                <div class="cms-search-page__suggestions">
                    <h2 class="cms-search-page__suggestions-title">Did you mean:</h2>
                    <ul class="cms-search-page__suggestion-list">
                        @foreach ($suggestions as $suggestion)
                            <li>
                                <a href="/search?q={{ urlencode($suggestion) }}" class="cms-search-page__suggestion-link">
                                    {{ $suggestion }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Pagination --}}
            @if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1)
                <nav class="cms-pagination" aria-label="Search results pagination">
                    <ul class="cms-pagination__list">
                        @if (($pagination['currentPage'] ?? 1) > 1)
                            <li>
                                <a href="/search?q={{ urlencode($query) }}&amp;page={{ ($pagination['currentPage'] ?? 1) - 1 }}"
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
                                    <a href="/search?q={{ urlencode($query) }}&amp;page={{ $p }}"
                                       class="cms-pagination__link"
                                       aria-label="Page {{ $p }}">
                                        {{ $p }}
                                    </a>
                                @endif
                            </li>
                        @endfor

                        @if (($pagination['currentPage'] ?? 1) < ($pagination['totalPages'] ?? 1))
                            <li>
                                <a href="/search?q={{ urlencode($query) }}&amp;page={{ ($pagination['currentPage'] ?? 1) + 1 }}"
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
    @endif
</div>
@endsection
