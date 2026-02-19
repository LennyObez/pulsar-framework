@extends('admin.layout')

@section('title', 'Comment Detail')

@section('content')
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
                    <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/approve" class="cms-inline-form">
                        @csrf
                        <button type="submit" class="cms-btn cms-btn--success">Approve</button>
                    </form>
                @endif
                @if (($comment['status'] ?? '') !== 'rejected')
                    <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/reject" class="cms-inline-form" data-cms-confirm="Reject this comment? Please provide a reason." data-cms-confirm-reason>
                        @csrf
                        <button type="submit" class="cms-btn cms-btn--warning">Reject</button>
                    </form>
                @endif
                @if (($comment['status'] ?? '') !== 'spam')
                    <form method="POST" action="/admin/cms/comments/{{ $comment['id'] }}/spam" class="cms-inline-form">
                        @csrf
                        <button type="submit" class="cms-btn cms-btn--danger">Mark as Spam</button>
                    </form>
                @endif
            @endcan
            <a href="/admin/cms/comments" class="cms-btn cms-btn--outline">Back to List</a>
        </div>
    </header>

    <div class="cms-comment-detail__layout">
        {{-- Comment body --}}
        <article class="cms-comment-detail__body-panel">
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

            {{-- Moderation history --}}
            @if (!empty($moderationHistory))
                <div class="cms-comment-detail__history">
                    <h2 class="cms-comment-detail__section-title">Moderation History</h2>
                    <table class="cms-table cms-table--compact">
                        <thead class="cms-table__head">
                            <tr>
                                <th class="cms-table__th">Action</th>
                                <th class="cms-table__th">Moderator</th>
                                <th class="cms-table__th">Reason</th>
                                <th class="cms-table__th">Date</th>
                            </tr>
                        </thead>
                        <tbody class="cms-table__body">
                            @foreach ($moderationHistory as $entry)
                                <tr class="cms-table__row">
                                    <td class="cms-table__td">{{ ucfirst($entry['action'] ?? '') }}</td>
                                    <td class="cms-table__td">{{ $entry['moderator_name'] ?? $entry['moderator_id'] ?? '' }}</td>
                                    <td class="cms-table__td">{{ $entry['reason'] ?? '(No reason given)' }}</td>
                                    <td class="cms-table__td">
                                        <time datetime="{{ $entry['created_at'] ?? '' }}">{{ $entry['created_at_human'] ?? $entry['created_at'] ?? '' }}</time>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
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
                            {{ $comment['author_name'] ?? 'Anonymous' }}
                            @if (isset($comment['is_guest']) && $comment['is_guest'])
                                <span class="cms-badge cms-badge--draft">Guest</span>
                            @endif
                        </dd>

                        @if (isset($comment['author_email']))
                            <dt class="cms-detail-list__term">Email</dt>
                            <dd class="cms-detail-list__value">{{ $comment['author_email'] }}</dd>
                        @endif

                        @if (isset($comment['user_id']))
                            <dt class="cms-detail-list__term">User ID</dt>
                            <dd class="cms-detail-list__value"><code>{{ $comment['user_id'] }}</code></dd>
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
                            <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($comment['ip_hash'] ?? '', 0, 16) }}...</code></dd>
                        @endif

                        @if (isset($comment['user_agent_hash']))
                            <dt class="cms-detail-list__term">UA Hash</dt>
                            <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($comment['user_agent_hash'] ?? '', 0, 16) }}...</code></dd>
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
                            <time datetime="{{ $comment['created_at'] ?? '' }}">{{ $comment['created_at_human'] ?? $comment['created_at'] ?? '' }}</time>
                        </dd>

                        @if (isset($comment['edited_at']))
                            <dt class="cms-detail-list__term">Edited</dt>
                            <dd class="cms-detail-list__value">
                                <time datetime="{{ $comment['edited_at'] }}">{{ $comment['edited_at_human'] ?? $comment['edited_at'] }}</time>
                            </dd>
                        @endif

                        @if (isset($comment['moderated_at']))
                            <dt class="cms-detail-list__term">Last Moderated</dt>
                            <dd class="cms-detail-list__value">
                                <time datetime="{{ $comment['moderated_at'] }}">{{ $comment['moderated_at_human'] ?? $comment['moderated_at'] }}</time>
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
