@extends('admin.layout')

@section('title', 'Comment Moderation Queue')

@section('content')
<div class="cms-comment-moderation">
    <header class="cms-comment-moderation__header">
        <h1 class="cms-comment-moderation__title">
            Moderation Queue
            @if (($pendingCount ?? 0) > 0)
                <span class="cms-widget__badge" aria-label="{{ $pendingCount }} comments pending moderation">{{ $pendingCount }} pending</span>
            @endif
        </h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    {{-- Filter tabs --}}
    <nav class="cms-comment-moderation__tabs" aria-label="Comment status filter">
        <ul class="cms-comment-moderation__tab-list" role="tablist">
            <?php
            $__filterTabs = [
                'pending' => 'Pending',
                'approved' => 'Approved',
                'spam' => 'Spam',
            ];
            ?>
            @foreach ($__filterTabs as $tabValue => $tabLabel)
                <li role="presentation">
                    <a href="/admin/cms/comments/queue?filter={{ $tabValue }}"
                       class="cms-comment-moderation__tab @if (($filter ?? 'pending') === $tabValue) cms-comment-moderation__tab--active @endif"
                       role="tab"
                       aria-selected="{{ ($filter ?? 'pending') === $tabValue ? 'true' : 'false' }}">
                        {{ $tabLabel }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Bulk actions --}}
    <form method="POST" action="/admin/cms/comments/bulk" class="cms-comment-moderation__bulk" data-cms-bulk-form>
        @csrf

        <div class="cms-comment-moderation__bulk-actions">
            <select name="bulk_action" class="cms-filter-form__select" aria-label="Bulk action">
                <option value="">Bulk Actions</option>
                @can('cms.comments.moderate')
                    <option value="approve">Approve Selected</option>
                    <option value="reject">Reject Selected</option>
                    <option value="spam">Mark as Spam</option>
                @endcan
            </select>
            <button type="submit" class="cms-btn cms-btn--outline cms-btn--sm" data-cms-bulk-submit>Apply</button>
        </div>

        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th cms-table__th--checkbox" scope="col">
                        <input type="checkbox" aria-label="Select all" data-cms-select-all>
                    </th>
                    <th class="cms-table__th" scope="col">Content</th>
                    <th class="cms-table__th" scope="col">Author</th>
                    <th class="cms-table__th" scope="col">Excerpt</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="created_at">Submitted</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($comments))
                    <tr>
                        <td colspan="6" class="cms-table__empty">No comments found matching the current filter.</td>
                    </tr>
                @endif

                @foreach ($comments as $comment)
                    <tr class="cms-table__row" data-cms-comment-id="{{ $comment['id'] }}">
                        <td class="cms-table__td cms-table__td--checkbox">
                            <input type="checkbox" name="ids[]" value="{{ $comment['id'] }}" aria-label="Select comment by {{ $comment['guest_name'] ?? $comment['author_id'] ?? 'unknown' }}">
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
                            <div class="cms-comment-moderation__author">
                                <span class="cms-comment-moderation__author-name">{{ $comment['guest_name'] ?? 'Registered User' }}</span>
                                @if ($comment['author_id'] === null)
                                    <span class="cms-badge cms-badge--draft">Guest</span>
                                @endif
                            </div>
                        </td>
                        <td class="cms-table__td cms-table__td--body">
                            <div class="cms-comment-moderation__body-excerpt">
                                <a href="/admin/cms/comments/{{ $comment['id'] }}" class="cms-comment-moderation__link">
                                    {{ mb_strimwidth($comment['body'] ?? '', 0, 150, '...') }}
                                </a>
                            </div>
                        </td>
                        <td class="cms-table__td">
                            <time datetime="{{ $comment['created_at'] ?? '' }}">{{ $comment['created_at'] ?? '' }}</time>
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            @can('cms.comments.moderate')
                                <div class="cms-action-group" role="group" aria-label="Comment actions">
                                    @if (($comment['status'] ?? '') === 'pending')
                                        <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                                            @csrf
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--success">Approve</button>
                                        </form>
                                        <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                                            @csrf
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Reject</button>
                                        </form>
                                        <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                                            @csrf
                                            <input type="hidden" name="action" value="spam">
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
        'baseUrl' => '/admin/cms/comments/queue?filter=' . ($filter ?? 'pending'),
    ])
</div>
@endsection
