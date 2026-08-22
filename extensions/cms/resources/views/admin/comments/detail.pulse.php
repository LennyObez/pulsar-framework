@extends('admin.layout')

@section('title', 'Comment Detail')

@section('content')
<?php /** @var array<string, mixed> $comment */ /** @var array{total_comments: int, spam_count: int}|null $authorStats */ ?>
<div class="cms-comment-detail">
    <header class="cms-comment-detail__header">
        <div class="cms-comment-detail__meta">
            <h1 class="cms-comment-detail__title">Comment Detail</h1>
            <?php
            $__commentStatusClass = match ($comment['status'] ?? '') {
                'pending' => 'cms-badge cms-badge--in-review',
                'approved' => 'cms-badge cms-badge--approved',
                'rejected' => 'cms-badge cms-badge--archived',
                'spam' => 'cms-badge cms-badge--spam',
                default => 'cms-badge',
            };
?>
            <span class="{{ $__commentStatusClass }}" role="status">{{ ucfirst($comment['status'] ?? '') }}</span>
        </div>
        <div class="cms-comment-detail__actions">
            @can('cms.comments.moderate')
                @if (($comment['status'] ?? '') !== 'approved')
                    <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                        @csrf
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="cms-btn cms-btn--success">Approve</button>
                    </form>
                @endif
                @if (($comment['status'] ?? '') !== 'rejected')
                    <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form" data-cms-confirm="Reject this comment? Please provide a reason." data-cms-confirm-reason>
                        @csrf
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="cms-btn cms-btn--warning">Reject</button>
                    </form>
                @endif
                @if (($comment['status'] ?? '') !== 'spam')
                    <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-inline-form">
                        @csrf
                        <input type="hidden" name="action" value="spam">
                        <button type="submit" class="cms-btn cms-btn--danger">Mark as Spam</button>
                    </form>
                @endif
                <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/delete" class="cms-inline-form" data-cms-confirm="Permanently delete this comment? This cannot be undone.">
                    @csrf
                    <button type="submit" class="cms-btn cms-btn--danger cms-btn--outline">Delete</button>
                </form>
            @endcan
            <a href="/admin/cms/comments/queue" class="cms-btn cms-btn--outline">Back to Queue</a>
        </div>
    </header>

    <div class="cms-comment-detail__layout">
        {{-- Comment body --}}
        <article class="cms-comment-detail__body-panel">
            {{-- Parent comment context --}}
            @if (isset($parentComment) && $parentComment !== null)
                <div class="cms-comment-detail__parent-context">
                    <h2 class="cms-comment-detail__section-title">In Reply To</h2>
                    <blockquote class="cms-comment-detail__parent-quote">
                        <p>{{ mb_strimwidth($parentComment['body'] ?? '', 0, 300, '...') }}</p>
                        <footer class="cms-comment-detail__parent-footer">
                            <span>{{ $parentComment['guest_name'] ?? 'Registered User' }}</span>
                            &mdash;
                            <time datetime="{{ $parentComment['created_at'] ?? '' }}">{{ $parentComment['created_at'] ?? '' }}</time>
                            <a href="/admin/cms/comments/{{ $parentComment['id'] }}" class="cms-comment-detail__parent-link">View parent</a>
                        </footer>
                    </blockquote>
                </div>
            @endif

            <div class="cms-comment-detail__content">
                <h2 class="cms-comment-detail__section-title">Comment Body</h2>
                <div class="cms-comment-detail__body">
                    {{ $comment['body'] ?? '' }}
                </div>
            </div>

            {{-- Content context --}}
            <div class="cms-comment-detail__context">
                <h2 class="cms-comment-detail__section-title">Content Context</h2>
                @if (isset($comment['content_id']))
                    <p>
                        Posted on:
                        <a href="/admin/cms/content/{{ $comment['content_id'] }}" class="cms-comment-detail__content-link">
                            {{ $comment['content_title'] ?? 'View Content' }}
                        </a>
                    </p>
                @else
                    <p class="cms-widget__empty">Content reference not available.</p>
                @endif
            </div>

            {{-- Admin response --}}
            @can('cms.comments.moderate')
                <div class="cms-comment-detail__admin-response">
                    <h2 class="cms-comment-detail__section-title">Admin Response</h2>
                    <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/moderate" class="cms-comment-detail__response-form">
                        @csrf
                        <input type="hidden" name="action" value="approve">
                        <div class="cms-form-group">
                            <label for="admin-reason" class="cms-form-group__label">Moderation note (optional)</label>
                            <textarea id="admin-reason" name="reason" class="cms-form-group__textarea" rows="3" placeholder="Add a reason or note for this moderation action..."></textarea>
                        </div>
                        <div class="cms-comment-detail__response-actions">
                            <button type="submit" name="action" value="approve" class="cms-btn cms-btn--success cms-btn--sm">Approve with Note</button>
                            <button type="submit" name="action" value="reject" class="cms-btn cms-btn--warning cms-btn--sm">Reject with Note</button>
                        </div>
                    </form>
                </div>
            @endcan
        </article>

        {{-- Sidebar --}}
        <aside class="cms-comment-detail__sidebar">
            {{-- Author info --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Author Information</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Name</dt>
                        <dd class="cms-detail-list__value">
                            {{ $comment['guest_name'] ?? 'Registered User' }}
                            @if ($comment['author_id'] === null)
                                <span class="cms-badge cms-badge--draft">Guest</span>
                            @endif
                        </dd>

                        @if (isset($comment['guest_email']) && $comment['guest_email'] !== null)
                            <dt class="cms-detail-list__term">Email</dt>
                            <dd class="cms-detail-list__value">
                                {{ mb_substr($comment['guest_email'], 0, 3) }}***@{{ mb_substr(strrchr($comment['guest_email'], '@') ?: '', 1) }}
                            </dd>
                        @endif

                        @if (isset($comment['author_id']) && $comment['author_id'] !== null)
                            <dt class="cms-detail-list__term">User ID</dt>
                            <dd class="cms-detail-list__value"><code>{{ $comment['author_id'] }}</code></dd>
                        @endif

                        @if (isset($authorStats))
                            <dt class="cms-detail-list__term">Total Comments</dt>
                            <dd class="cms-detail-list__value">{{ $authorStats['total_comments'] ?? 0 }}</dd>

                            <dt class="cms-detail-list__term">Previous Comments</dt>
                            <dd class="cms-detail-list__value">{{ ($authorStats['total_comments'] ?? 1) - 1 }}</dd>

                            <dt class="cms-detail-list__term">Spam Rate</dt>
                            <dd class="cms-detail-list__value">
                                <?php
                    $__spamRate = ($authorStats['total_comments'] ?? 0) > 0
                        ? round(($authorStats['spam_count'] ?? 0) / $authorStats['total_comments'] * 100, 1)
                        : 0;
?>
                                <span class="@if ($__spamRate > 50) cms-text--danger @elseif ($__spamRate > 20) cms-text--warning @endif">
                                    {{ $__spamRate }}%
                                </span>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Technical details --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Technical Details</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Comment ID</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ $comment['id'] ?? '' }}</code></dd>

                        @if (isset($comment['ip_hash']))
                            <dt class="cms-detail-list__term">IP Hash</dt>
                            <dd class="cms-detail-list__value"><code class="cms-hash">{{ mb_substr($comment['ip_hash'] ?? '', 0, 16) }}...</code></dd>
                        @endif

                        @if (isset($comment['user_agent_hash']))
                            <dt class="cms-detail-list__term">UA Hash</dt>
                            <dd class="cms-detail-list__value"><code class="cms-hash">{{ mb_substr($comment['user_agent_hash'] ?? '', 0, 16) }}...</code></dd>
                        @endif

                        @if (isset($comment['data_classification']))
                            <dt class="cms-detail-list__term">Data Classification</dt>
                            <dd class="cms-detail-list__value">{{ ucfirst($comment['data_classification'] ?? '') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Timestamps --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Timestamps</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Created</dt>
                        <dd class="cms-detail-list__value">
                            <time datetime="{{ $comment['created_at'] ?? '' }}">{{ $comment['created_at'] ?? '' }}</time>
                        </dd>

                        @if (isset($comment['edited_at']) && $comment['edited_at'] !== null)
                            <dt class="cms-detail-list__term">Edited</dt>
                            <dd class="cms-detail-list__value">
                                <time datetime="{{ $comment['edited_at'] }}">{{ $comment['edited_at'] }}</time>
                            </dd>
                        @endif

                        @if (isset($comment['edit_window_expires_at']) && $comment['edit_window_expires_at'] !== null)
                            <dt class="cms-detail-list__term">Edit Window Expires</dt>
                            <dd class="cms-detail-list__value">
                                <time datetime="{{ $comment['edit_window_expires_at'] }}">{{ $comment['edit_window_expires_at'] }}</time>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>
        </aside>
    </div>
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
