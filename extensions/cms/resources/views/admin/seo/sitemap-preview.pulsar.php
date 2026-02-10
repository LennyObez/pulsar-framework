@extends('admin.layout')

@section('title', 'Sitemap Preview')

@section('content')
<div class="cms-sitemap-preview">
    <header class="cms-sitemap-preview__header">
        <h1 class="cms-sitemap-preview__title">Sitemap Preview</h1>
        <div class="cms-sitemap-preview__actions">
            @can('cms.seo.manage')
                <form method="POST" action="/admin/cms/seo/sitemap/regenerate" class="cms-inline-form">
                    @csrf
                    <button type="submit" class="cms-btn cms-btn--primary">Force Regenerate</button>
                </form>
            @endcan
            <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
        </div>
    </header>

    {{-- Last generated --}}
    @if (isset($lastGenerated))
        <p class="cms-sitemap-preview__meta">
            Last generated: <time datetime="{{ $lastGenerated }}">{{ $lastGeneratedHuman ?? $lastGenerated }}</time>
        </p>
    @endif

    {{-- Segment list --}}
    <section class="cms-sitemap-preview__segments" aria-labelledby="sitemap-segments-heading">
        <h2 class="cms-sitemap-preview__section-title" id="sitemap-segments-heading">Sitemap Segments</h2>

        @if (empty($segments))
            <p class="cms-sitemap-preview__empty">No sitemap segments found. The sitemap will be generated on the next request.</p>
        @endif

        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th" scope="col">#</th>
                    <th class="cms-table__th" scope="col">Sitemap URL</th>
                    <th class="cms-table__th" scope="col">Last Modified</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($segments))
                    <tr>
                        <td colspan="3" class="cms-table__empty">No sitemap segments available.</td>
                    </tr>
                @endif

                @foreach ($segments as $segIndex => $segment)
                    <tr class="cms-table__row">
                        <td class="cms-table__td">{{ $segIndex + 1 }}</td>
                        <td class="cms-table__td cms-table__td--title">
                            <code>{{ $segment['loc'] ?? '' }}</code>
                        </td>
                        <td class="cms-table__td">
                            @if (isset($segment['lastmod']) && $segment['lastmod'] !== null)
                                <time datetime="{{ $segment['lastmod'] }}">{{ $segment['lastmod'] }}</time>
                            @else
                                &mdash;
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Summary --}}
    <div class="cms-sitemap-preview__summary">
        <div class="cms-stats-grid">
            <div class="cms-stats-grid__item">
                <span class="cms-stats-grid__value">{{ $totalSegments ?? 0 }}</span>
                <span class="cms-stats-grid__label">Total Segments</span>
            </div>
            <div class="cms-stats-grid__item">
                <span class="cms-stats-grid__value">{{ $baseUrl ?? '' }}</span>
                <span class="cms-stats-grid__label">Base URL</span>
            </div>
        </div>
    </div>
</div>
@endsection
