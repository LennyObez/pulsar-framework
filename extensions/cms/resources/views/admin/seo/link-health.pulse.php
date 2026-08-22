@extends('admin.layout')

@section('title', 'Link Health')

@section('content')
<div class="cms-link-health">
    <header class="cms-link-health__header">
        <h1 class="cms-link-health__title">Link Health</h1>
        <div class="cms-link-health__actions">
            @can('cms.seo.manage')
                <form method="POST" action="/admin/cms/seo/link-health/check" class="cms-inline-form">
                    @csrf
                    <button type="submit" class="cms-btn cms-btn--primary">Re-check All Links</button>
                </form>
            @endcan
            <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
        </div>
    </header>

    {{-- Summary stats --}}
    @if (isset($stats))
        <div class="cms-link-health__stats">
            <div class="cms-stats-grid">
                <div class="cms-stats-grid__item">
                    <span class="cms-stats-grid__value">{{ $stats['total_checked'] ?? 0 }}</span>
                    <span class="cms-stats-grid__label">Total Links</span>
                </div>
                <div class="cms-stats-grid__item cms-stats-grid__item--archived">
                    <span class="cms-stats-grid__value">{{ $stats['broken'] ?? 0 }}</span>
                    <span class="cms-stats-grid__label">Broken</span>
                </div>
                <div class="cms-stats-grid__item cms-stats-grid__item--in-review">
                    <span class="cms-stats-grid__value">{{ $stats['redirected'] ?? 0 }}</span>
                    <span class="cms-stats-grid__label">Redirected</span>
                </div>
                <div class="cms-stats-grid__item cms-stats-grid__item--published">
                    <span class="cms-stats-grid__value">{{ $stats['healthy'] ?? 0 }}</span>
                    <span class="cms-stats-grid__label">Healthy</span>
                </div>
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="cms-link-health__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/seo/link-health" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-type" class="cms-filter-form__label">Status</label>
                <select id="filter-type" name="type" class="cms-filter-form__select">
                    <option value="">All</option>
                    <option value="broken" @if (($filters['type'] ?? '') === 'broken') selected @endif>Broken Only</option>
                    <option value="redirected" @if (($filters['type'] ?? '') === 'redirected') selected @endif>Redirected Only</option>
                </select>
            </div>

            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    {{-- Broken links table --}}
    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">Source Content</th>
                <th class="cms-table__th" scope="col">Locale</th>
                <th class="cms-table__th" scope="col">Target URL</th>
                <th class="cms-table__th" scope="col">Status</th>
                <th class="cms-table__th" scope="col">HTTP Code</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="last_checked_at">Last Checked</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($links))
                <tr>
                    <td colspan="6" class="cms-table__empty">No broken or redirected links found. All links are healthy.</td>
                </tr>
            @endif

            @foreach ($links as $link)
                <tr class="cms-table__row" data-cms-link-id="{{ $link['id'] }}">
                    <td class="cms-table__td">
                        @if (isset($link['source_content_id']))
                            <a href="/admin/cms/content/{{ $link['source_content_id'] }}">
                                {{ $link['source_content_title'] ?? 'View Content' }}
                            </a>
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="cms-table__td">
                        <span class="cms-badge cms-badge--draft">{{ strtoupper($link['source_locale'] ?? '') }}</span>
                    </td>
                    <td class="cms-table__td cms-table__td--title">
                        <code class="cms-link-health__url">{{ $link['target_url'] ?? '' }}</code>
                    </td>
                    <td class="cms-table__td">
                        @if ($link['is_broken'] ?? false)
                            <span class="cms-badge cms-badge--archived" role="status">Broken</span>
                        @elseif ($link['is_redirected'] ?? false)
                            <span class="cms-badge cms-badge--in-review" role="status">Redirected</span>
                        @else
                            <span class="cms-badge cms-badge--published" role="status">OK</span>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @if (isset($link['http_status_code']) && $link['http_status_code'] !== null)
                            <code>{{ $link['http_status_code'] }}</code>
                        @else
                            <span class="cms-badge cms-badge--archived">Unreachable</span>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        <time datetime="{{ $link['last_checked_at'] ?? '' }}">{{ $link['last_checked_at_human'] ?? $link['last_checked_at'] ?? '' }}</time>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 50,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/seo/link-health',
    ])
</div>
@endsection
