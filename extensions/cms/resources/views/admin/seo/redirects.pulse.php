@extends('admin.layout')

@section('title', 'SEO Redirects')

@section('content')
<div class="cms-seo-redirects">
    <header class="cms-seo-redirects__header">
        <h1 class="cms-seo-redirects__title">Redirects</h1>
        <div class="cms-seo-redirects__actions">
            @can('cms.seo.view')
                <a href="/admin/cms/seo/redirects/export" class="cms-btn cms-btn--outline">Export CSV</a>
            @endcan
            <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
        </div>
    </header>

    {{-- Create redirect form --}}
    @can('cms.seo.manage')
        <section class="cms-seo-redirects__create" aria-labelledby="create-redirect-heading">
            <h2 class="cms-seo-redirects__section-title" id="create-redirect-heading">Add Redirect</h2>
            <form method="POST" action="/admin/cms/seo/redirects" class="cms-form">
                @csrf
                <div class="cms-form__row">
                    <div class="cms-form-group">
                        <label for="from-path" class="cms-form-group__label">From Path</label>
                        <input type="text"
                               id="from-path"
                               name="from_path"
                               class="cms-form-group__input"
                               placeholder="/old-page"
                               required
                               aria-required="true">
                    </div>
                    <div class="cms-form-group">
                        <label for="to-path" class="cms-form-group__label">To Path</label>
                        <input type="text"
                               id="to-path"
                               name="to_path"
                               class="cms-form-group__input"
                               placeholder="/new-page"
                               required
                               aria-required="true">
                    </div>
                    <div class="cms-form-group">
                        <label for="status-code" class="cms-form-group__label">Status Code</label>
                        <select id="status-code" name="status_code" class="cms-filter-form__select">
                            <option value="301">301 Permanent</option>
                            <option value="308">308 Permanent (Preserve Method)</option>
                        </select>
                    </div>
                    <div class="cms-form-group">
                        <label for="redirect-locale" class="cms-form-group__label">Locale (optional)</label>
                        <input type="text"
                               id="redirect-locale"
                               name="locale"
                               class="cms-form-group__input"
                               placeholder="en, fr, de...">
                    </div>
                </div>
                <button type="submit" class="cms-btn cms-btn--primary">Add Redirect</button>
            </form>
        </section>
    @endcan

    {{-- Bulk import --}}
    @can('cms.seo.manage')
        <section class="cms-seo-redirects__import" aria-labelledby="bulk-import-heading">
            <h2 class="cms-seo-redirects__section-title" id="bulk-import-heading">Bulk Import</h2>
            <form method="POST" action="/admin/cms/seo/redirects/import" enctype="multipart/form-data" class="cms-form">
                @csrf
                <div class="cms-form__row">
                    <div class="cms-form-group">
                        <label for="import-file" class="cms-form-group__label">CSV File</label>
                        <input type="file"
                               id="import-file"
                               name="file"
                               class="cms-form-group__input"
                               accept=".csv,text/csv"
                               required
                               aria-required="true">
                        <p class="cms-form-group__hint">Format: from_path,to_path,status_code (one redirect per line)</p>
                    </div>
                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox" name="dry_run" value="1" class="cms-form-group__checkbox">
                            Dry Run (preview without saving)
                        </label>
                    </div>
                </div>
                <button type="submit" class="cms-btn cms-btn--outline">Import</button>
            </form>
        </section>
    @endcan

    {{-- Redirects table --}}
    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">From Path</th>
                <th class="cms-table__th" scope="col">To Path</th>
                <th class="cms-table__th" scope="col">Status</th>
                <th class="cms-table__th" scope="col">Locale</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="hits">Hits</th>
                <th class="cms-table__th" scope="col">Last Hit</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="created_at">Created</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($redirects))
                <tr>
                    <td colspan="8" class="cms-table__empty">No redirects configured. Add your first redirect above.</td>
                </tr>
            @endif

            @foreach ($redirects as $redirect)
                <tr class="cms-table__row" data-cms-redirect-id="{{ $redirect['id'] }}">
                    <td class="cms-table__td cms-table__td--title"><code>{{ $redirect['from_path'] ?? '' }}</code></td>
                    <td class="cms-table__td"><code>{{ $redirect['to_path'] ?? '' }}</code></td>
                    <td class="cms-table__td">
                        <span class="cms-badge cms-badge--{{ ($redirect['status_code'] ?? 301) === 301 ? 'published' : 'in-review' }}">
                            {{ $redirect['status_code'] ?? 301 }}
                        </span>
                    </td>
                    <td class="cms-table__td">{{ $redirect['locale'] ?? '&mdash;' }}</td>
                    <td class="cms-table__td">{{ $redirect['hits'] ?? 0 }}</td>
                    <td class="cms-table__td">
                        @if (isset($redirect['last_hit_at']) && $redirect['last_hit_at'] !== null)
                            <time datetime="{{ $redirect['last_hit_at'] }}">{{ $redirect['last_hit_at_human'] ?? $redirect['last_hit_at'] }}</time>
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="cms-table__td">
                        <time datetime="{{ $redirect['created_at'] ?? '' }}">{{ $redirect['created_at_human'] ?? $redirect['created_at'] ?? '' }}</time>
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        @can('cms.seo.manage')
                            <form method="POST" action="/admin/cms/seo/redirects/{{ $redirect['id'] }}" class="cms-inline-form" data-cms-confirm="Delete this redirect? This cannot be undone.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 50,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/seo/redirects',
    ])
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
