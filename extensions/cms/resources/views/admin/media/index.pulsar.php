@extends('admin.layout')

@section('title', 'Media Library')

@section('content')
<div class="cms-media-library" data-cms-media-library>
    <header class="cms-media-library__header">
        <h1 class="cms-media-library__title">Media Library</h1>
        <div class="cms-media-library__actions">
            @can('cms.media.upload')
                <button type="button" class="cms-btn cms-btn--primary" data-cms-media-upload-trigger>Upload Files</button>
            @endcan
            <div class="cms-media-library__view-toggle" role="group" aria-label="View mode">
                <button type="button"
                        class="cms-btn cms-btn--outline cms-btn--sm cms-media-library__view-btn"
                        data-cms-media-view="grid"
                        aria-pressed="true"
                        title="Grid view">&#9638; Grid</button>
                <button type="button"
                        class="cms-btn cms-btn--outline cms-btn--sm cms-media-library__view-btn"
                        data-cms-media-view="list"
                        aria-pressed="false"
                        title="List view">&#9776; List</button>
            </div>
        </div>
    </header>

    {{-- Upload zone --}}
    @can('cms.media.upload')
        <div class="cms-upload-zone" data-cms-upload-zone role="region" aria-label="File upload area">
            <div class="cms-upload-zone__inner">
                <span class="cms-upload-zone__icon" aria-hidden="true">&#128228;</span>
                <p class="cms-upload-zone__text">Drag and drop files here or click to browse</p>
                <p class="cms-upload-zone__hint">Supported: images, SVG, PDF, documents. Max {{ $maxUploadSize ?? '10MB' }} per file.</p>
                <input type="file"
                       class="cms-upload-zone__input"
                       data-cms-upload-input
                       multiple
                       accept="image/*,.svg,.pdf,.doc,.docx"
                       aria-label="Choose files to upload">
            </div>
            <div class="cms-upload-zone__progress" data-cms-upload-progress hidden>
                <ul class="cms-upload-zone__file-list" data-cms-upload-file-list></ul>
            </div>
        </div>
    @endcan

    {{-- Filters --}}
    <div class="cms-media-library__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/media" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-mime" class="cms-filter-form__label">Type</label>
                <select id="filter-mime" name="mime_type" class="cms-filter-form__select">
                    <option value="">All Types</option>
                    <option value="image" @if (($filters['mime_type'] ?? '') === 'image') selected @endif>Images</option>
                    <option value="image/svg+xml" @if (($filters['mime_type'] ?? '') === 'image/svg+xml') selected @endif>SVG</option>
                    <option value="application/pdf" @if (($filters['mime_type'] ?? '') === 'application/pdf') selected @endif>PDF</option>
                    <option value="video" @if (($filters['mime_type'] ?? '') === 'video') selected @endif>Video</option>
                    <option value="audio" @if (($filters['mime_type'] ?? '') === 'audio') selected @endif>Audio</option>
                    <option value="document" @if (($filters['mime_type'] ?? '') === 'document') selected @endif>Documents</option>
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

            <div class="cms-filter-form__group">
                <label for="filter-visibility" class="cms-filter-form__label">Visibility</label>
                <select id="filter-visibility" name="visibility" class="cms-filter-form__select">
                    <option value="">All</option>
                    <option value="public" @if (($filters['visibility'] ?? '') === 'public') selected @endif>Public</option>
                    <option value="private" @if (($filters['visibility'] ?? '') === 'private') selected @endif>Private</option>
                    <option value="unlisted" @if (($filters['visibility'] ?? '') === 'unlisted') selected @endif>Unlisted</option>
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-uploader" class="cms-filter-form__label">Uploader</label>
                <input type="text" id="filter-uploader" name="uploader" value="{{ $filters['uploader'] ?? '' }}" class="cms-filter-form__input" placeholder="Search by uploader">
            </div>

            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    {{-- Bulk actions --}}
    <form method="POST" action="/admin/cms/media/bulk" class="cms-media-library__bulk" data-cms-bulk-form>
        @csrf

        <div class="cms-media-library__bulk-actions">
            <select name="bulk_action" class="cms-filter-form__select" aria-label="Bulk action">
                <option value="">Bulk Actions</option>
                @can('cms.media.delete')
                    <option value="delete">Delete Selected</option>
                @endcan
            </select>
            <button type="submit" class="cms-btn cms-btn--outline" data-cms-bulk-submit data-cms-confirm="Delete selected media assets? This action requires a reason." data-cms-confirm-reason>Apply</button>
        </div>

        {{-- Grid view --}}
        <div class="cms-media-grid" data-cms-media-grid>
            @if (empty($items))
                <p class="cms-media-grid__empty">No media assets found. Upload your first file to get started.</p>
            @endif

            @foreach ($items as $item)
                <div class="cms-media-grid__card" data-cms-media-id="{{ $item['id'] }}">
                    <div class="cms-media-grid__checkbox">
                        <input type="checkbox" name="ids[]" value="{{ $item['id'] }}" aria-label="Select {{ $item['filename'] ?? 'asset' }}">
                    </div>
                    <div class="cms-media-grid__preview">
                        @if (str_starts_with($item['mime_type'] ?? '', 'image/'))
                            <img src="{{ $item['thumbnail_url'] ?? $item['url'] ?? '' }}"
                                 alt="{{ $item['alt_text'] ?? $item['filename'] ?? '' }}"
                                 class="cms-media-grid__thumbnail"
                                 loading="lazy">
                        @elseif (($item['mime_type'] ?? '') === 'application/pdf')
                            <span class="cms-media-grid__icon cms-media-grid__icon--pdf" aria-hidden="true">&#128196;</span>
                        @elseif (str_starts_with($item['mime_type'] ?? '', 'video/'))
                            <span class="cms-media-grid__icon cms-media-grid__icon--video" aria-hidden="true">&#127910;</span>
                        @elseif (str_starts_with($item['mime_type'] ?? '', 'audio/'))
                            <span class="cms-media-grid__icon cms-media-grid__icon--audio" aria-hidden="true">&#127925;</span>
                        @else
                            <span class="cms-media-grid__icon cms-media-grid__icon--file" aria-hidden="true">&#128196;</span>
                        @endif
                    </div>
                    <div class="cms-media-grid__info">
                        <a href="/admin/cms/media/{{ $item['id'] }}" class="cms-media-grid__name" title="{{ $item['filename'] ?? '' }}">
                            {{ $item['filename'] ?? '(No filename)' }}
                        </a>
                        <span class="cms-media-grid__meta">{{ $item['human_size'] ?? '' }}</span>
                        @if (isset($item['derivatives_status']))
                            <span class="cms-badge cms-badge--{{ ($item['derivatives_status'] ?? '') === 'complete' ? 'published' : 'draft' }}">
                                {{ ($item['derivatives_status'] ?? '') === 'complete' ? 'Processed' : 'Pending' }}
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- List view (hidden by default) --}}
        <table class="cms-table cms-media-list" data-cms-media-list hidden>
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th cms-table__th--checkbox" scope="col">
                        <input type="checkbox" aria-label="Select all" data-cms-select-all>
                    </th>
                    <th class="cms-table__th" scope="col">Preview</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="filename">Filename</th>
                    <th class="cms-table__th" scope="col">Type</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="size">Size</th>
                    <th class="cms-table__th" scope="col">Dimensions</th>
                    <th class="cms-table__th" scope="col">Derivatives</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="uploaded_at">Uploaded</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($items))
                    <tr>
                        <td colspan="9" class="cms-table__empty">No media assets found. Upload your first file to get started.</td>
                    </tr>
                @endif

                @foreach ($items as $item)
                    <tr class="cms-table__row" data-cms-media-id="{{ $item['id'] }}">
                        <td class="cms-table__td cms-table__td--checkbox">
                            <input type="checkbox" name="ids[]" value="{{ $item['id'] }}" aria-label="Select {{ $item['filename'] ?? 'asset' }}">
                        </td>
                        <td class="cms-table__td cms-table__td--preview">
                            @if (str_starts_with($item['mime_type'] ?? '', 'image/'))
                                <img src="{{ $item['thumbnail_url'] ?? $item['url'] ?? '' }}"
                                     alt="{{ $item['alt_text'] ?? $item['filename'] ?? '' }}"
                                     class="cms-media-list__thumb"
                                     loading="lazy">
                            @else
                                <span class="cms-media-list__icon" aria-hidden="true">&#128196;</span>
                            @endif
                        </td>
                        <td class="cms-table__td cms-table__td--title">
                            <a href="/admin/cms/media/{{ $item['id'] }}">{{ $item['filename'] ?? '' }}</a>
                        </td>
                        <td class="cms-table__td">{{ $item['mime_type'] ?? '' }}</td>
                        <td class="cms-table__td">{{ $item['human_size'] ?? '' }}</td>
                        <td class="cms-table__td">
                            @if (isset($item['width']) && isset($item['height']))
                                {{ $item['width'] }}&times;{{ $item['height'] }}
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="cms-table__td">
                            @if (isset($item['derivatives_status']))
                                <span class="cms-badge cms-badge--{{ ($item['derivatives_status'] ?? '') === 'complete' ? 'published' : 'draft' }}">
                                    {{ ($item['derivatives_status'] ?? '') === 'complete' ? 'Complete' : 'Pending' }}
                                </span>
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="cms-table__td">
                            <time datetime="{{ $item['created_at'] ?? '' }}">{{ $item['created_at_human'] ?? $item['created_at'] ?? '' }}</time>
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            <div class="cms-action-group" role="group" aria-label="Media actions">
                                <a href="/admin/cms/media/{{ $item['id'] }}" class="cms-btn cms-btn--sm cms-btn--outline">View</a>
                                @can('cms.media.delete')
                                    <form method="POST" action="/admin/cms/media/{{ $item['id'] }}" class="cms-inline-form" data-cms-confirm="Delete this media asset? This cannot be undone." data-cms-confirm-reason>
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Delete</button>
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
        'baseUrl' => '/admin/cms/media',
    ])
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
