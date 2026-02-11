@extends('admin.layout')

@section('title', 'Content Management')

@section('content')
<div class="cms-content-list">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Content</h1>
        <div class="cms-content-list__actions">
            @can('cms.content.create')
                <a href="/admin/cms/content/create?type=article" class="cms-btn cms-btn--primary">
                    New Article
                </a>
                <a href="/admin/cms/content/create?type=page" class="cms-btn cms-btn--secondary">
                    New Page
                </a>
            @endcan
        </div>
    </header>

    <div class="cms-content-list__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/content" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-type" class="cms-filter-form__label">Type</label>
                <select id="filter-type" name="type" class="cms-filter-form__select">
                    <option value="">All Types</option>
                    <option value="article" @if (($filters['type'] ?? '') === 'article') selected @endif>Article</option>
                    <option value="page" @if (($filters['type'] ?? '') === 'page') selected @endif>Page</option>
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-status" class="cms-filter-form__label">Status</label>
                <select id="filter-status" name="status" class="cms-filter-form__select">
                    <option value="">All Statuses</option>
                    <option value="draft" @if (($filters['status'] ?? '') === 'draft') selected @endif>Draft</option>
                    <option value="in_review" @if (($filters['status'] ?? '') === 'in_review') selected @endif>In Review</option>
                    <option value="approved" @if (($filters['status'] ?? '') === 'approved') selected @endif>Approved</option>
                    <option value="scheduled" @if (($filters['status'] ?? '') === 'scheduled') selected @endif>Scheduled</option>
                    <option value="published" @if (($filters['status'] ?? '') === 'published') selected @endif>Published</option>
                    <option value="archived" @if (($filters['status'] ?? '') === 'archived') selected @endif>Archived</option>
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-locale" class="cms-filter-form__label">Locale</label>
                <select id="filter-locale" name="locale" class="cms-filter-form__select">
                    @foreach ($locales as $loc)
                        <option value="{{ $loc }}" @if (($filters['locale'] ?? '') === $loc) selected @endif>{{ strtoupper($loc) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-date-from" class="cms-filter-form__label">From</label>
                <input type="date" id="filter-date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="cms-filter-form__input">
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-date-to" class="cms-filter-form__label">To</label>
                <input type="date" id="filter-date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="cms-filter-form__input">
            </div>

            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    <form method="POST" action="/admin/cms/content/bulk" class="cms-content-list__bulk" data-cms-bulk-form>
        @csrf

        <div class="cms-content-list__bulk-actions">
            <select name="bulk_action" class="cms-filter-form__select" aria-label="Bulk action">
                <option value="">Bulk Actions</option>
                @can('cms.content.publish')
                    <option value="publish">Publish Selected</option>
                @endcan
                @can('cms.content.archive')
                    <option value="archive">Archive Selected</option>
                @endcan
                @can('cms.content.delete')
                    <option value="delete">Delete Selected</option>
                @endcan
            </select>
            <button type="submit" class="cms-btn cms-btn--outline" data-cms-bulk-submit>Apply</button>
        </div>

        <table class="cms-table" data-cms-sortable-table>
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th cms-table__th--checkbox" scope="col">
                        <input type="checkbox" aria-label="Select all" data-cms-select-all>
                    </th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="title">Title</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="type">Type</th>
                    <th class="cms-table__th" scope="col">Status</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="author">Author</th>
                    <th class="cms-table__th" scope="col">Locale</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="updated_at">Last Modified</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                {{-- Skeleton loading rows --}}
                <tr class="cms-table__skeleton-row" data-cms-skeleton aria-hidden="true" hidden>
                    <td colspan="8"><div class="cms-skeleton cms-skeleton--table-row"></div></td>
                </tr>
                <tr class="cms-table__skeleton-row" data-cms-skeleton aria-hidden="true" hidden>
                    <td colspan="8"><div class="cms-skeleton cms-skeleton--table-row"></div></td>
                </tr>
                <tr class="cms-table__skeleton-row" data-cms-skeleton aria-hidden="true" hidden>
                    <td colspan="8"><div class="cms-skeleton cms-skeleton--table-row"></div></td>
                </tr>
                <tr class="cms-table__skeleton-row" data-cms-skeleton aria-hidden="true" hidden>
                    <td colspan="8"><div class="cms-skeleton cms-skeleton--table-row"></div></td>
                </tr>
                <tr class="cms-table__skeleton-row" data-cms-skeleton aria-hidden="true" hidden>
                    <td colspan="8"><div class="cms-skeleton cms-skeleton--table-row"></div></td>
                </tr>

                @if (empty($items))
                    <tr>
                        <td colspan="8" class="cms-table__empty">No content found. Create your first content item to get started.</td>
                    </tr>
                @endif

                @foreach ($items as $item)
                    <tr class="cms-table__row" data-cms-content-id="{{ $item['id'] }}">
                        <td class="cms-table__td cms-table__td--checkbox">
                            <input type="checkbox" name="ids[]" value="{{ $item['id'] }}" aria-label="Select {{ $item['title'] ?? 'item' }}">
                        </td>
                        <td class="cms-table__td cms-table__td--title">
                            <a href="/admin/cms/content/{{ $item['id'] }}/edit" class="cms-content-list__link">
                                {{ $item['title'] ?? '(Untitled)' }}
                            </a>
                        </td>
                        <td class="cms-table__td">
                            <span class="cms-type-label">{{ ucfirst($item['type'] ?? 'page') }}</span>
                        </td>
                        <td class="cms-table__td">
                            @include('cms::admin._partials.status-badge', ['status' => $item['status'] ?? 'draft'])
                        </td>
                        <td class="cms-table__td">{{ $item['author_name'] ?? $item['author_id'] ?? '' }}</td>
                        <td class="cms-table__td">{{ strtoupper($item['locale'] ?? '') }}</td>
                        <td class="cms-table__td">
                            <time datetime="{{ $item['updated_at'] ?? '' }}">{{ $item['updated_at_human'] ?? $item['updated_at'] ?? '' }}</time>
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            <div class="cms-action-group" role="group" aria-label="Content actions">
                                @can('cms.content.edit')
                                    <a href="/admin/cms/content/{{ $item['id'] }}/edit" class="cms-btn cms-btn--sm cms-btn--outline" title="Edit">Edit</a>
                                @endcan
                                <a href="/admin/cms/content/{{ $item['id'] }}" class="cms-btn cms-btn--sm cms-btn--outline" title="Preview">Preview</a>
                                @if (($item['status'] ?? '') !== 'published')
                                    @can('cms.content.publish')
                                        <form method="POST" action="/admin/cms/content/{{ $item['id'] }}/publish" class="cms-inline-form">
                                            @csrf
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--success" title="Publish">Publish</button>
                                        </form>
                                    @endcan
                                @endif
                                @if (($item['status'] ?? '') === 'published')
                                    @can('cms.content.archive')
                                        <form method="POST" action="/admin/cms/content/{{ $item['id'] }}/archive" class="cms-inline-form">
                                            @csrf
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning" title="Archive">Archive</button>
                                        </form>
                                    @endcan
                                @endif
                                @can('cms.content.delete')
                                    <form method="POST" action="/admin/cms/content/{{ $item['id'] }}" class="cms-inline-form" data-cms-confirm="Are you sure you want to delete this content?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger" title="Delete">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </form>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 20,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/content',
    ])
</div>
@endsection
