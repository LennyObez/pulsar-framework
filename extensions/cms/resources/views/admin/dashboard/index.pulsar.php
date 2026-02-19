@extends('admin.layout')

@section('title', 'CMS Dashboard')

@section('content')
<div class="cms-dashboard">
    <header class="cms-dashboard__header">
        <h1 class="cms-dashboard__title">CMS Dashboard</h1>
        <div class="cms-dashboard__quick-actions">
            @can('cms.content.create')
                <a href="/admin/cms/content/create?type=article" class="cms-btn cms-btn--primary">New Article</a>
                <a href="/admin/cms/content/create?type=page" class="cms-btn cms-btn--secondary">New Page</a>
            @endcan
            @can('cms.menus.view')
                <a href="/admin/cms/menus" class="cms-btn cms-btn--outline">Manage Menus</a>
            @endcan
            @can('cms.taxonomy.view')
                <a href="/admin/cms/taxonomy" class="cms-btn cms-btn--outline">Manage Taxonomies</a>
            @endcan
        </div>
    </header>

    <div class="cms-dashboard__widgets">
        {{-- Content Statistics --}}
        <section class="cms-widget" aria-labelledby="widget-stats">
            <h2 class="cms-widget__title" id="widget-stats">Content Statistics</h2>
            <div class="cms-widget__body">
                <div class="cms-stats-grid">
                    <div class="cms-stats-grid__item">
                        <span class="cms-stats-grid__value">{{ $stats['total'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">Total</span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--published">
                        <span class="cms-stats-grid__value">{{ $stats['published'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">Published</span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--draft">
                        <span class="cms-stats-grid__value">{{ $stats['draft'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">Drafts</span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--scheduled">
                        <span class="cms-stats-grid__value">{{ $stats['scheduled'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">Scheduled</span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--in-review">
                        <span class="cms-stats-grid__value">{{ $stats['in_review'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">In Review</span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--archived">
                        <span class="cms-stats-grid__value">{{ $stats['archived'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">Archived</span>
                    </div>
                </div>
            </div>
        </section>

        {{-- Pending Reviews --}}
        @can('cms.content.approve')
            <section class="cms-widget" aria-labelledby="widget-reviews">
                <h2 class="cms-widget__title" id="widget-reviews">
                    Pending Reviews
                    @if (($pendingReviewCount ?? 0) > 0)
                        <span class="cms-widget__badge">{{ $pendingReviewCount }}</span>
                    @endif
                </h2>
                <div class="cms-widget__body">
                    @if (empty($pendingReviews))
                        <p class="cms-widget__empty">No pending reviews.</p>
                    @else
                        <ul class="cms-review-list">
                            @foreach ($pendingReviews as $review)
                                <li class="cms-review-list__item">
                                    <a href="/admin/cms/reviews" class="cms-review-list__link">
                                        <span class="cms-review-list__content">{{ $review['content_title'] ?? 'Untitled' }}</span>
                                        <span class="cms-review-list__meta">
                                            by {{ $review['requested_by_name'] ?? $review['requested_by'] ?? '' }}
                                            &mdash;
                                            <time datetime="{{ $review['created_at'] ?? '' }}">{{ $review['created_at_human'] ?? $review['created_at'] ?? '' }}</time>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <a href="/admin/cms/reviews" class="cms-btn cms-btn--outline cms-btn--full">View All Reviews</a>
                    @endif
                </div>
            </section>
        @endcan

        {{-- Recent Activity --}}
        <section class="cms-widget" aria-labelledby="widget-activity">
            <h2 class="cms-widget__title" id="widget-activity">Recent Activity</h2>
            <div class="cms-widget__body">
                @if (empty($recentActivity))
                    <p class="cms-widget__empty">No recent activity.</p>
                @else
                    <ul class="cms-activity-list">
                        @foreach ($recentActivity as $event)
                            <li class="cms-activity-list__item">
                                <span class="cms-activity-list__action">{{ $event['action'] ?? '' }}</span>
                                <span class="cms-activity-list__detail">{{ $event['description'] ?? '' }}</span>
                                <span class="cms-activity-list__meta">
                                    {{ $event['user_name'] ?? '' }} &mdash;
                                    <time datetime="{{ $event['created_at'] ?? '' }}">{{ $event['created_at_human'] ?? $event['created_at'] ?? '' }}</time>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- Quick Links --}}
        <section class="cms-widget" aria-labelledby="widget-links">
            <h2 class="cms-widget__title" id="widget-links">Quick Links</h2>
            <div class="cms-widget__body">
                <nav class="cms-quick-links" aria-label="Quick links">
                    <ul class="cms-quick-links__list">
                        @can('cms.content.view')
                            <li><a href="/admin/cms/content" class="cms-quick-links__link">All Content</a></li>
                            <li><a href="/admin/cms/content?status=draft" class="cms-quick-links__link">Drafts</a></li>
                            <li><a href="/admin/cms/content?status=published" class="cms-quick-links__link">Published</a></li>
                        @endcan
                        @can('cms.menus.view')
                            <li><a href="/admin/cms/menus" class="cms-quick-links__link">Menus</a></li>
                        @endcan
                        @can('cms.taxonomy.view')
                            <li><a href="/admin/cms/taxonomy" class="cms-quick-links__link">Taxonomies</a></li>
                        @endcan
                        @can('cms.settings.view')
                            <li><a href="/admin/cms/settings" class="cms-quick-links__link">Settings</a></li>
                        @endcan
                    </ul>
                </nav>
            </div>
        </section>
    </div>
</div>
@endsection
