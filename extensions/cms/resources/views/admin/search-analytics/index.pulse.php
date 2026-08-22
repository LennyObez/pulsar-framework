@extends('admin.layout')

@section('title', 'Search Analytics')

@section('content')
<div class="cms-search-analytics">
    <header class="cms-search-analytics__header">
        <h1 class="cms-search-analytics__title">Search Analytics</h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    {{-- Date range filter --}}
    <div class="cms-search-analytics__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/search-analytics" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-date-from" class="cms-filter-form__label">From</label>
                <input type="date" id="filter-date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="cms-filter-form__input">
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-date-to" class="cms-filter-form__label">To</label>
                <input type="date" id="filter-date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="cms-filter-form__input">
            </div>

            <button type="submit" class="cms-btn cms-btn--outline">Apply</button>
        </form>
    </div>

    {{-- Stats cards --}}
    <div class="cms-search-analytics__stats">
        <div class="cms-stats-grid">
            <div class="cms-stats-grid__item">
                <span class="cms-stats-grid__value">{{ $stats['total_searches'] ?? 0 }}</span>
                <span class="cms-stats-grid__label">Total Searches</span>
            </div>
            <div class="cms-stats-grid__item">
                <span class="cms-stats-grid__value">{{ $stats['unique_queries'] ?? 0 }}</span>
                <span class="cms-stats-grid__label">Unique Queries</span>
            </div>
            <div class="cms-stats-grid__item cms-stats-grid__item--{{ ($stats['zero_result_rate'] ?? 0) > 20 ? 'archived' : 'published' }}">
                <span class="cms-stats-grid__value">{{ $stats['zero_result_rate'] ?? 0 }}%</span>
                <span class="cms-stats-grid__label">Zero-Result Rate</span>
            </div>
            <div class="cms-stats-grid__item cms-stats-grid__item--published">
                <span class="cms-stats-grid__value">{{ $stats['avg_ctr'] ?? '0.0' }}%</span>
                <span class="cms-stats-grid__label">Avg CTR</span>
            </div>
        </div>
    </div>

    {{-- Daily query volume chart (CSS-only) --}}
    @if (!empty($dailyVolume))
        <?php /** @var list<array{date: string, count: int}> $dailyVolume */ ?>
        <section class="cms-search-analytics__chart" aria-labelledby="chart-daily-volume">
            <h2 class="cms-search-analytics__section-title" id="chart-daily-volume">Daily Query Volume</h2>
            <div class="cms-bar-chart" role="img" aria-label="Bar chart showing daily search query volume">
                <?php
                $__counts = array_column($dailyVolume, 'count');
        $__maxVolume = $__counts !== [] ? max($__counts) : 1;
        $__maxVolume = $__maxVolume > 0 ? $__maxVolume : 1;
        ?>
                <div class="cms-bar-chart__bars">
                    @foreach ($dailyVolume as $day)
                        <?php /** @var array{date: string, count: int} $day */ $__pct = round(($day['count'] / $__maxVolume) * 100); ?>
                        <div class="cms-bar-chart__bar-group">
                            <div class="cms-bar-chart__bar"
                                 style="--bar-height: {{ $__pct }}%"
                                 title="{{ $day['date'] ?? '' }}: {{ $day['count'] }} searches"
                                 aria-label="{{ $day['date'] ?? '' }}: {{ $day['count'] }} searches">
                                <span class="cms-bar-chart__value">{{ $day['count'] }}</span>
                            </div>
                            <span class="cms-bar-chart__label">{{ $day['label'] ?? '' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <div class="cms-search-analytics__tables">
        {{-- Top queries --}}
        <section class="cms-search-analytics__section" aria-labelledby="section-top-queries">
            <h2 class="cms-search-analytics__section-title" id="section-top-queries">Top Queries</h2>
            <table class="cms-table">
                <thead class="cms-table__head">
                    <tr>
                        <th class="cms-table__th" scope="col">#</th>
                        <th class="cms-table__th" scope="col">Query</th>
                        <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="count">Searches</th>
                        <th class="cms-table__th" scope="col">Avg Results</th>
                        <th class="cms-table__th" scope="col">CTR</th>
                    </tr>
                </thead>
                <tbody class="cms-table__body">
                    @if (empty($topQueries))
                        <tr>
                            <td colspan="5" class="cms-table__empty">No search data available for the selected period.</td>
                        </tr>
                    @endif

                    @foreach ($topQueries as $qIndex => $query)
                        <tr class="cms-table__row">
                            <td class="cms-table__td">{{ $qIndex + 1 }}</td>
                            <td class="cms-table__td cms-table__td--title"><code>{{ $query['query_text'] ?? '' }}</code></td>
                            <td class="cms-table__td">{{ $query['search_count'] ?? 0 }}</td>
                            <td class="cms-table__td">{{ $query['avg_results'] ?? 0 }}</td>
                            <td class="cms-table__td">
                                <span class="cms-search-analytics__ctr">{{ $query['ctr'] ?? '0.0' }}%</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- Zero-result queries --}}
        <section class="cms-search-analytics__section" aria-labelledby="section-zero-results">
            <h2 class="cms-search-analytics__section-title" id="section-zero-results">Zero-Result Queries</h2>
            <table class="cms-table">
                <thead class="cms-table__head">
                    <tr>
                        <th class="cms-table__th" scope="col">Query</th>
                        <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="count">Count</th>
                        <th class="cms-table__th" scope="col">Last Searched</th>
                    </tr>
                </thead>
                <tbody class="cms-table__body">
                    @if (empty($zeroResultQueries))
                        <tr>
                            <td colspan="3" class="cms-table__empty">No zero-result queries found. Your search index is covering all queries.</td>
                        </tr>
                    @endif

                    @foreach ($zeroResultQueries as $zQuery)
                        <tr class="cms-table__row">
                            <td class="cms-table__td cms-table__td--title"><code>{{ $zQuery['query_text'] ?? '' }}</code></td>
                            <td class="cms-table__td">{{ $zQuery['count'] ?? 0 }}</td>
                            <td class="cms-table__td">
                                <time datetime="{{ $zQuery['last_searched'] ?? '' }}">{{ $zQuery['last_searched_human'] ?? $zQuery['last_searched'] ?? '' }}</time>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>
</div>
@endsection
