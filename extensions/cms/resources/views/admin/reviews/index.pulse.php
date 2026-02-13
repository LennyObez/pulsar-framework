@extends('admin.layout')

@section('title', 'Editorial Reviews')

@section('content')
<div class="cms-review-queue">
    <header class="cms-review-queue__header">
        <h1 class="cms-review-queue__title">
            Editorial Reviews
            @if (($totalPending ?? 0) > 0)
                <span class="cms-widget__badge" aria-label="{{ $totalPending }} pending reviews">{{ $totalPending }} pending</span>
            @endif
        </h1>
        <a href="/admin/cms/content" class="cms-btn cms-btn--outline">Back to Content</a>
    </header>

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">Content</th>
                <th class="cms-table__th" scope="col">Locale</th>
                <th class="cms-table__th" scope="col">Requested By</th>
                <th class="cms-table__th" scope="col">Reviewer</th>
                <th class="cms-table__th" scope="col">Status</th>
                <th class="cms-table__th" scope="col">Submitted</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($reviews))
                <tr>
                    <td colspan="7" class="cms-table__empty">No pending reviews. All content has been reviewed.</td>
                </tr>
            @endif

            @foreach ($reviews as $review)
                <tr class="cms-table__row">
                    <td class="cms-table__td cms-table__td--title">
                        <a href="/admin/cms/content/{{ $review['content_id'] }}">{{ $review['content_title'] ?? 'Untitled' }}</a>
                    </td>
                    <td class="cms-table__td">{{ strtoupper($review['locale'] ?? '') }}</td>
                    <td class="cms-table__td">{{ $review['requested_by_name'] ?? $review['requested_by'] ?? '' }}</td>
                    <td class="cms-table__td">{{ $review['reviewer_name'] ?? $review['reviewer_id'] ?? 'Unassigned' }}</td>
                    <td class="cms-table__td">
                        <?php
                        $__reviewBadgeClass = match ($review['status'] ?? '') {
                            'pending' => 'cms-badge cms-badge--draft',
                            'in_review' => 'cms-badge cms-badge--in-review',
                            'approved' => 'cms-badge cms-badge--approved',
                            'rejected' => 'cms-badge cms-badge--archived',
                            default => 'cms-badge',
                        };
                        ?>
                        <span class="{{ $__reviewBadgeClass }}" role="status">{{ ucfirst(str_replace('_', ' ', $review['status'] ?? '')) }}</span>
                    </td>
                    <td class="cms-table__td">
                        <time datetime="{{ $review['created_at'] ?? '' }}">{{ $review['created_at_human'] ?? $review['created_at'] ?? '' }}</time>
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        @if (($review['status'] ?? '') === 'pending' || ($review['status'] ?? '') === 'in_review')
                            <div class="cms-action-group" role="group" aria-label="Review actions">
                                <form method="POST" action="/admin/cms/reviews/{{ $review['id'] }}/approve" class="cms-inline-form">
                                    @csrf
                                    <div class="cms-action-group__expand" data-cms-expand>
                                        <label for="approve-reason-{{ $review['id'] }}" class="cms-form-group__label cms-sr-only">Reason</label>
                                        <input type="text"
                                               id="approve-reason-{{ $review['id'] }}"
                                               name="reason"
                                               class="cms-form-group__input cms-form-group__input--sm"
                                               placeholder="Approval note (optional)">
                                    </div>
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--success">Approve</button>
                                </form>

                                <form method="POST" action="/admin/cms/reviews/{{ $review['id'] }}/reject" class="cms-inline-form">
                                    @csrf
                                    <div class="cms-action-group__expand" data-cms-expand>
                                        <label for="reject-reason-{{ $review['id'] }}" class="cms-form-group__label cms-sr-only">Reason</label>
                                        <input type="text"
                                               id="reject-reason-{{ $review['id'] }}"
                                               name="reason"
                                               class="cms-form-group__input cms-form-group__input--sm"
                                               placeholder="Rejection reason"
                                               required>
                                        <label for="reject-comment-{{ $review['id'] }}" class="cms-form-group__label cms-sr-only">Comment</label>
                                        <textarea id="reject-comment-{{ $review['id'] }}"
                                                  name="comment"
                                                  class="cms-form-group__textarea cms-form-group__textarea--sm"
                                                  rows="2"
                                                  placeholder="Feedback for the author (optional)"></textarea>
                                    </div>
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Reject</button>
                                </form>
                            </div>
                        @else
                            <span class="cms-table__muted">
                                {{ ucfirst($review['status'] ?? '') }}
                                @if (isset($review['decided_at']))
                                    on <time datetime="{{ $review['decided_at'] }}">{{ $review['decided_at'] }}</time>
                                @endif
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
