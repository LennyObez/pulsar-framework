@extends('admin.layout')

@section('title', 'Comment Moderation')

@section('content')
<div class="cms-comment-moderation">
    <header class="cms-comment-moderation__header">
        <h1 class="cms-comment-moderation__title">
            Comment Moderation
            @if (($totalPending ?? 0) > 0)
                <span class="cms-widget__badge">{{ $totalPending }} pending</span>
            @endif
        </h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    {{-- Status tabs --}}
    <nav class="cms-comment-moderation__tabs" aria-label="Comment status filter">
        <ul class="cms-comment-moderation__tab-list" role="tablist">
            <?php
            $__statusTabs = [
                'pending' => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'spam' => 'Spam',
                '' => 'All',
            ];
            ?>
            @foreach ($__statusTabs as $tabValue => $tabLabel)
                <li role="presentation">
                    <a href="/admin/cms/comments{{ $tabValue !== '' ? '?status=' . $tabValue : '' }}"
                       class="cms-comment-moderation__tab @if (($activeStatus ?? 'pending') === $tabValue || ($tabValue === '' && !isset($activeStatus))) cms-comment-moderation__tab--active @endif"
                       role="tab"
                       aria-selected="{{ ($activeStatus ?? 'pending') === $tabValue ? 'true' : 'false' }}">
                        {{ $tabLabel }}
                        @if ($tabValue !== '' && isset($statusCounts[$tabValue]) && $statusCounts[$tabValue] > 0)
                            <span class="cms-comment-moderation__tab-count">{{ $statusCounts[$tabValue] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Filters --}}
    <div class="cms-comment-moderation__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/comments" class="cms-filter-form">
            @if (isset($activeStatus) && $activeStatus !== '')
                <input type="hidden" name="status" value="{{ $activeStatus }}">
            @endif

            <div class="cms-filter-form__group">
                <label for="filter-search" class="cms-filter-form__label">Search</label>
                <input type="text" id="filter-search" name="search" value="{{ $filters['search'] ?? '' }}" class="cms-filter-form__input" placeholder="Search comment body...">
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

    {{-- Bulk moderation --}}
    <form method="POST" action="/admin/cms/comments/bulk" class="cms-comment-moderation__bulk" data-cms-bulk-form>
        @csrf

        <div class="cms-comment-moderation__bulk-actions">
            <select name="bulk_action" class="cms-filter-form__select" aria-label="Bulk action">
                <option value="">Bulk Actions</option>
                @can('cms.comments.moderate')
                    <option value="approve">Approve All Selected</option>
                    <option value="reject">Reject All Selected</option>
                    <option value="spam">Mark All as Spam</option>
                @endcan
            </select>
            <button type="submit" class="cms-btn cms-btn--outline" data-cms-bulk-submit>Apply</button>
        </div>

        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th cms-table__th--checkbox">
                        <input type="checkbox" aria-label="Select all" data-cms-select-all>
                    </th>
                    <th class="cms-table__th">Author</th>
                    <th class="cms-table__th">Comment</th>
                    <th class="cms-table__th">Content</th>
                    <th class="cms-table__th cms-table__th--sortable" data-cms-sort="created_at">Date</th>
                    <th class="cms-table__th">Status</th>
                    <th class="cms-table__th">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($comments))
                    <tr>
                        <td colspan="7" class="cms-table__empty">No comments found matching the current filters.</td>
                    </tr>
                @endif

                @foreach ($comments as $comment)
                    <tr class="cms-table__row" data-cms-comment-id="{{ $comment['id'] }}">
                        <td class="cms-table__td cms-table__td--checkbox">
                            <input type="checkbox" name="ids[]" value="{{ $comment['id'] }}" aria-label="Select comment by {{ $comment['author_name'] ?? 'unknown' }}">
                        </td>
                        <td class="cms-table__td">
                            <div class="cms-comment-moderation__author">
                                <span class="cms-comment-moderation__author-name">{{ $comment['author_name'] ?? 'Anonymous' }}</span>
                                @if (isset($comment['is_guest']) && $comment['is_guest'])
                                    <span class="cms-badge cms-badge--draft">Guest</span>
                                @endif
                            </div>
                        </td>
                        <td class="cms-table__td cms-table__td--body">
                            <div class="cms-comment-moderation__body-excerpt">
                                <a href="/admin/cms/comments/{{ $comment['id'] }}" class="cms-comment-moderation__link">
                                    {{ mb_strimwidth($comment['body'] ?? '', 0, 200, '...') }}
                                </a>
                            </div>
                        </td>
                        <td class="cms-table__td">
                            @if (isset($comment['content_id']))
                                <a href="/admin/cms/content/{{ $comment['content_id'] }}" class="cms-comment-moderation__content-link">
                                    {{ $comment['content_title'] ?? 'View Content' }}
                                </a>
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="cms-table__td">
                            <time datetime="{{ $comment['created_at'] ?? '' }}">{{ $comment['created_at_human'] ?? $comment['created_at'] ?? '' }}</time>
                        </td>
                        <td class="cms-table__td">
                            <?php
                            $__commentBadgeClass = match ($comment['status'] ?? '') {
                                'pending' => 'cms-badge cms-badge--in-review',
                                'approved' => 'cms-badge cms-badge--approved',
                                'rejected' => 'cms-badge cms-badge--archived',
                                'spam' => 'cms-badge cms-badge--spam',
                                default => 'cms-badge',
                            };
            ?>
                            <span class="{{ $__commentBadgeClass }}" role="status">{{ ucfirst($comment['status'] ?? '') }}</span>
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            @can('cms.comments.moderate')
                                <div class="cms-action-group" role="group" aria-label="Comment moderation actions">
                                    @if (($comment['status'] ?? '') !== 'approved')
                                        <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/approve" class="cms-inline-form">
                                            @csrf
                                            <div class="cms-action-group__expand" data-cms-expand>
                                                <label for="approve-reason-{{ $comment['id'] }}" class="cms-form-group__label cms-sr-only">Reason</label>
                                                <input type="text"
                                                       id="approve-reason-{{ $comment['id'] }}"
                                                       name="reason"
                                                       class="cms-form-group__input cms-form-group__input--sm"
                                                       placeholder="Note (optional)">
                                            </div>
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--success">Approve</button>
                                        </form>
                                    @endif

                                    @if (($comment['status'] ?? '') !== 'rejected')
                                        <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/reject" class="cms-inline-form">
                                            @csrf
                                            <div class="cms-action-group__expand" data-cms-expand>
                                                <label for="reject-reason-{{ $comment['id'] }}" class="cms-form-group__label cms-sr-only">Reason</label>
                                                <input type="text"
                                                       id="reject-reason-{{ $comment['id'] }}"
                                                       name="reason"
                                                       class="cms-form-group__input cms-form-group__input--sm"
                                                       placeholder="Rejection reason"
                                                       required>
                                            </div>
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Reject</button>
                                        </form>
                                    @endif

                                    @if (($comment['status'] ?? '') !== 'spam')
                                        <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/spam" class="cms-inline-form">
                                            @csrf
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Spam</button>
                                        </form>
                                    @endif
                                </div>
                            @endcan
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
        'baseUrl' => '/admin/cms/comments' . (isset($activeStatus) && $activeStatus !== '' ? '?status=' . $activeStatus : ''),
    ])
</div>
@endsection
