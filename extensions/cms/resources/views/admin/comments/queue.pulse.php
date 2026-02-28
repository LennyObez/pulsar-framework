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
                        @if (isset($filterCounts[$tabValue]) && $filterCounts[$tabValue] > 0)
                            <span class="cms-comment-moderation__tab-count" aria-label="{{ $filterCounts[$tabValue] }} {{ $tabLabel }}">{{ $filterCounts[$tabValue] }}</span>
                        @endif
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

        @if (empty($comments))
            <div class="cms-comment-moderation__empty">
                <p class="cms-table__empty">No comments found matching the current filter.</p>
            </div>
        @endif

        {{-- Comment cards --}}
        <div class="cms-comment-moderation__cards">
            @foreach ($comments as $comment)
                <article class="cms-comment-moderation__card" data-cms-comment-id="{{ $comment['id'] }}">
                    <div class="cms-comment-moderation__card-select">
                        <input type="checkbox" name="ids[]" value="{{ $comment['id'] }}" aria-label="Select comment by {{ $comment['guest_name'] ?? $comment['author_id'] ?? 'unknown' }}">
                    </div>
                    <div class="cms-comment-moderation__card-body">
                        <div class="cms-comment-moderation__card-meta">
                            <span class="cms-comment-moderation__author-name">{{ $comment['guest_name'] ?? 'Registered User' }}</span>
                            @if (isset($comment['guest_email']))
                                <span class="cms-comment-moderation__author-email">({{ $comment['guest_email'] }})</span>
                            @endif
                            @if ($comment['author_id'] === null)
                                <span class="cms-badge cms-badge--draft">Guest</span>
                            @endif
                            <span class="cms-comment-moderation__separator">&middot;</span>
                            <time datetime="{{ $comment['created_at'] ?? '' }}" class="cms-comment-moderation__timestamp">{{ $comment['created_at_human'] ?? $comment['created_at'] ?? '' }}</time>
                        </div>
                        <div class="cms-comment-moderation__card-content">
                            <a href="/admin/cms/comments/{{ $comment['id'] }}" class="cms-comment-moderation__link">
                                {{ mb_strimwidth($comment['body'] ?? '', 0, 300, '...') }}
                            </a>
                        </div>
                        <div class="cms-comment-moderation__card-context">
                            @if (isset($comment['content_id']))
                                On: <a href="/admin/cms/content/{{ $comment['content_id'] }}" class="cms-comment-moderation__content-link">
                                    {{ $comment['content_title'] ?? 'View Content' }}
                                </a>
                            @endif
                        </div>
                    </div>
                    <div class="cms-comment-moderation__card-actions">
                        @can('cms.comments.moderate')
                            @if (($comment['status'] ?? '') !== 'approved')
                                <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                                    @csrf
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--success">Approve</button>
                                </form>
                            @endif
                            @if (($comment['status'] ?? '') !== 'rejected')
                                <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                                    @csrf
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Reject</button>
                                </form>
                            @endif
                            @if (($comment['status'] ?? '') !== 'spam')
                                <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                                    @csrf
                                    <input type="hidden" name="action" value="spam">
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Spam</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </article>
            @endforeach
        </div>
    </form>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 20,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/comments/queue?filter=' . ($filter ?? 'pending'),
    ])
</div>
@endsection
