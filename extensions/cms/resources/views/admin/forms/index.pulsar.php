@extends('admin.layout')

@section('title', 'Form Submissions')

@section('content')
<div class="cms-form-submissions">
    <header class="cms-form-submissions__header">
        <h1 class="cms-form-submissions__title">
            Form Submissions
            @if (($unread_count ?? 0) > 0)
                <span class="cms-widget__badge" aria-label="{{ $unread_count }} unread submissions">{{ $unread_count }} unread</span>
            @endif
        </h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    {{-- Filter tabs --}}
    <nav class="cms-form-submissions__tabs" aria-label="Submission filter">
        <ul class="cms-form-submissions__tab-list" role="tablist">
            <?php
            $__filterTabs = [
                'all' => 'All',
                'unread' => 'Unread',
                'spam' => 'Spam',
            ];
            ?>
            @foreach ($__filterTabs as $tabValue => $tabLabel)
                <li role="presentation">
                    <a href="/admin/cms/forms{{ $tabValue !== 'all' ? '?filter=' . $tabValue : '' }}"
                       class="cms-form-submissions__tab @if (($filter ?? 'all') === $tabValue) cms-form-submissions__tab--active @endif"
                       role="tab"
                       aria-selected="{{ ($filter ?? 'all') === $tabValue ? 'true' : 'false' }}">
                        {{ $tabLabel }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Bulk actions --}}
    <form method="POST" action="/admin/cms/forms/bulk-delete" class="cms-form-submissions__bulk" data-cms-bulk-form>
        @csrf

        <div class="cms-form-submissions__bulk-actions">
            <button type="submit" class="cms-btn cms-btn--danger cms-btn--sm" data-cms-bulk-submit onclick="return confirm('Delete selected submissions?')">Delete Selected</button>
        </div>

        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th cms-table__th--checkbox" scope="col">
                        <input type="checkbox" aria-label="Select all" data-cms-select-all>
                    </th>
                    <th class="cms-table__th" scope="col">Date</th>
                    <th class="cms-table__th" scope="col">Form</th>
                    <th class="cms-table__th" scope="col">Preview</th>
                    <th class="cms-table__th" scope="col">Status</th>
                    <th class="cms-table__th" scope="col">Spam</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($submissions))
                    <tr>
                        <td colspan="7" class="cms-table__empty">No form submissions found.</td>
                    </tr>
                @endif

                @foreach ($submissions as $submission)
                    <tr class="cms-table__row @if (!$submission['is_read']) cms-table__row--unread @endif">
                        <td class="cms-table__td cms-table__td--checkbox">
                            <input type="checkbox" name="ids[]" value="{{ $submission['id'] }}" aria-label="Select submission">
                        </td>
                        <td class="cms-table__td">
                            <time datetime="{{ $submission['submitted_at'] }}">{{ $submission['submitted_at'] }}</time>
                        </td>
                        <td class="cms-table__td">
                            <a href="/admin/cms/content/{{ $submission['content_id'] }}" class="cms-form-submissions__content-link">
                                {{ $submission['content_id'] }}
                            </a>
                        </td>
                        <td class="cms-table__td cms-table__td--preview">
                            <a href="/admin/cms/forms/{{ $submission['id'] }}" class="cms-form-submissions__link">
                                {{ $submission['data_preview'] }}
                            </a>
                        </td>
                        <td class="cms-table__td">
                            @if ($submission['is_read'])
                                <span class="cms-badge cms-badge--approved">Read</span>
                            @else
                                <span class="cms-badge cms-badge--in-review">Unread</span>
                            @endif
                        </td>
                        <td class="cms-table__td">
                            @if ($submission['is_spam'])
                                <span class="cms-badge cms-badge--spam" title="Score: {{ $submission['spam_score'] }}">Spam</span>
                            @else
                                <span class="cms-badge cms-badge--approved">Clean</span>
                            @endif
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            <div class="cms-action-group" role="group" aria-label="Submission actions">
                                <a href="/admin/cms/forms/{{ $submission['id'] }}" class="cms-btn cms-btn--sm cms-btn--outline">View</a>
                                @if (!$submission['is_spam'])
                                    <form method="POST" action="/admin/cms/forms/{{ $submission['id'] }}/spam" class="cms-inline-form">
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Spam</button>
                                    </form>
                                @endif
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
        'total' => 0,
        'baseUrl' => '/admin/cms/forms' . (isset($filter) && $filter !== 'all' ? '?filter=' . $filter : ''),
    ])
</div>
@endsection
