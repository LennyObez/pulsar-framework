@extends('admin.layout')

@section('title', 'CMS Dashboard')

@section('content')
<div class="cms-dashboard">
    <header class="cms-dashboard__header">
        <h1 class="cms-dashboard__title">CMS Dashboard</h1>
    </header>

    {{-- Quick Actions --}}
    <section class="cms-dashboard__quick-actions-bar" aria-labelledby="widget-quick-actions">
        <h2 class="sr-only" id="widget-quick-actions">Quick Actions</h2>
        <div class="cms-quick-actions-row" role="group" aria-label="Quick actions">
            @foreach (($widgets['quick_actions']['data']['actions'] ?? []) as $action)
                <a href="{{ $action['url'] }}"
                    class="cms-btn cms-btn--action cms-btn--action-{{ $action['icon'] }}"
                    @if (($action['target'] ?? '') === '_blank') target="_blank" rel="noopener noreferrer" @endif
                    aria-label="{{ $action['label'] }}">
                    <span class="cms-btn__icon cms-btn__icon--{{ $action['icon'] }}" aria-hidden="true"></span>
                    <span class="cms-btn__label">{{ $action['label'] }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="cms-dashboard__grid">
        {{-- Content Status Widget --}}
        <section class="cms-widget" aria-labelledby="widget-content-status">
            <h2 class="cms-widget__title" id="widget-content-status">Content Status</h2>
            <div class="cms-widget__body" data-cms-widget-content="content-status">
                {{-- Skeleton loading state --}}
                <div class="cms-widget__skeleton" data-cms-skeleton aria-hidden="true" hidden>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.75rem">
                        <div class="cms-skeleton cms-skeleton--card" style="height:60px"></div>
                        <div class="cms-skeleton cms-skeleton--card" style="height:60px"></div>
                        <div class="cms-skeleton cms-skeleton--card" style="height:60px"></div>
                        <div class="cms-skeleton cms-skeleton--card" style="height:60px"></div>
                    </div>
                    <div class="cms-skeleton cms-skeleton--text" style="width:80%;margin-top:1rem"></div>
                </div>
                @php
                    $contentData = $widgets['content_status']['data'] ?? [];
                    $counts = $contentData['counts'] ?? [];
                    $sparkline = $contentData['sparkline'] ?? [];
                    $total = $contentData['total'] ?? 0;
                @endphp
                <div class="cms-stats-grid">
                    <div class="cms-stats-grid__item cms-stats-grid__item--draft">
                        <span class="cms-stats-grid__value" aria-label="{{ $counts['draft'] ?? 0 }} drafts">{{ $counts['draft'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">
                            <span class="cms-badge cms-badge--draft" aria-hidden="true"></span>
                            Draft
                        </span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--scheduled">
                        <span class="cms-stats-grid__value" aria-label="{{ $counts['scheduled'] ?? 0 }} scheduled">{{ $counts['scheduled'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">
                            <span class="cms-badge cms-badge--scheduled" aria-hidden="true"></span>
                            Scheduled
                        </span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--published">
                        <span class="cms-stats-grid__value" aria-label="{{ $counts['published'] ?? 0 }} published">{{ $counts['published'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">
                            <span class="cms-badge cms-badge--published" aria-hidden="true"></span>
                            Published
                        </span>
                    </div>
                    <div class="cms-stats-grid__item cms-stats-grid__item--archived">
                        <span class="cms-stats-grid__value" aria-label="{{ $counts['archived'] ?? 0 }} archived">{{ $counts['archived'] ?? 0 }}</span>
                        <span class="cms-stats-grid__label">
                            <span class="cms-badge cms-badge--archived" aria-hidden="true"></span>
                            Archived
                        </span>
                    </div>
                </div>

                @if (!empty($sparkline))
                    <div class="cms-sparkline" aria-hidden="true">
                        <span class="cms-sparkline__label">14-day trend</span>
                        <div class="cms-sparkline__chart" role="img" aria-label="Content creation trend over 14 days">
                            @php
                                $maxVal = max($sparkline) ?: 1;
                            @endphp
                            @foreach ($sparkline as $i => $val)
                                <div class="cms-sparkline__bar"
                                    style="--bar-height: {{ ($val / $maxVal) * 100 }}%"
                                    title="Day {{ $i + 1 }}: {{ $val }}"></div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="cms-widget__footer">
                    <span class="cms-widget__total" aria-label="{{ $total }} total content items">{{ $total }} total</span>
                </div>
            </div>
        </section>

        {{-- Moderation Queue Widget --}}
        <section class="cms-widget" aria-labelledby="widget-moderation">
            <h2 class="cms-widget__title" id="widget-moderation">
                Moderation Queue
                {{-- Skeleton loading state --}}
                <div class="cms-widget__skeleton" data-cms-skeleton aria-hidden="true" hidden style="display:flex;gap:0.5rem;margin-left:auto">
                    <div class="cms-skeleton cms-skeleton--button" style="width:2rem;height:1.5rem"></div>
                </div>
                @php
                    $modData = $widgets['moderation_queue']['data'] ?? [];
                    $pendingCount = $modData['pending_count'] ?? 0;
                    $urgentCount = $modData['urgent_count'] ?? 0;
                @endphp
                @if ($pendingCount > 0)
                    <span class="cms-widget__badge" aria-label="{{ $pendingCount }} pending comments">{{ $pendingCount }}</span>
                @endif
            </h2>
            <div class="cms-widget__body">
                @if ($pendingCount === 0)
                    <p class="cms-widget__empty">No comments awaiting moderation.</p>
                @else
                    <div class="cms-moderation-summary">
                        <div class="cms-moderation-summary__stat">
                            <span class="cms-moderation-summary__count">{{ $pendingCount }}</span>
                            <span class="cms-moderation-summary__label">Pending</span>
                        </div>
                        @if ($urgentCount > 0)
                            <div class="cms-moderation-summary__stat cms-moderation-summary__stat--urgent">
                                <span class="cms-moderation-summary__count">{{ $urgentCount }}</span>
                                <span class="cms-moderation-summary__label">
                                    <span class="cms-badge cms-badge--urgent" aria-hidden="true"></span>
                                    Urgent ({{ $modData['urgent_threshold_hours'] ?? 24 }}h+)
                                </span>
                            </div>
                        @endif
                    </div>
                    <a href="{{ $modData['moderation_url'] ?? '/admin/cms/comments?status=pending' }}" class="cms-btn cms-btn--outline cms-btn--full">
                        Review Comments
                    </a>
                @endif
            </div>
        </section>

        {{-- Recent Activity Widget --}}
        <section class="cms-widget" aria-labelledby="widget-activity">
            <h2 class="cms-widget__title" id="widget-activity">Recent Activity</h2>
            <div class="cms-widget__body" data-cms-widget-content="activity">
                {{-- Skeleton loading state --}}
                <div class="cms-widget__skeleton" data-cms-skeleton aria-hidden="true" hidden style="display:flex;flex-direction:column;gap:0.75rem">
                    <div style="display:flex;gap:0.75rem;align-items:center">
                        <div class="cms-skeleton cms-skeleton--avatar" style="width:0.5rem;height:0.5rem"></div>
                        <div style="flex:1;display:flex;flex-direction:column;gap:0.25rem">
                            <div class="cms-skeleton cms-skeleton--text" style="width:70%"></div>
                            <div class="cms-skeleton cms-skeleton--text" style="width:40%;height:0.75rem"></div>
                        </div>
                    </div>
                    <div style="display:flex;gap:0.75rem;align-items:center">
                        <div class="cms-skeleton cms-skeleton--avatar" style="width:0.5rem;height:0.5rem"></div>
                        <div style="flex:1;display:flex;flex-direction:column;gap:0.25rem">
                            <div class="cms-skeleton cms-skeleton--text" style="width:55%"></div>
                            <div class="cms-skeleton cms-skeleton--text" style="width:30%;height:0.75rem"></div>
                        </div>
                    </div>
                    <div style="display:flex;gap:0.75rem;align-items:center">
                        <div class="cms-skeleton cms-skeleton--avatar" style="width:0.5rem;height:0.5rem"></div>
                        <div style="flex:1;display:flex;flex-direction:column;gap:0.25rem">
                            <div class="cms-skeleton cms-skeleton--text" style="width:65%"></div>
                            <div class="cms-skeleton cms-skeleton--text" style="width:35%;height:0.75rem"></div>
                        </div>
                    </div>
                </div>
                @php
                    $activityData = $widgets['recent_activity']['data'] ?? [];
                    $entries = $activityData['entries'] ?? [];
                @endphp
                @if (empty($entries))
                    <p class="cms-widget__empty">No recent activity.</p>
                @else
                    <ul class="cms-activity-list" role="list">
                        @foreach ($entries as $entry)
                            <li class="cms-activity-list__item">
                                <span class="cms-activity-list__icon cms-activity-list__icon--{{ $entry['outcome'] ?? 'success' }}" aria-hidden="true"></span>
                                <div class="cms-activity-list__content">
                                    <span class="cms-activity-list__action">{{ $entry['action'] ?? '' }}</span>
                                    @if (!empty($entry['resource']))
                                        <span class="cms-activity-list__resource">{{ $entry['resource'] }}</span>
                                    @endif
                                </div>
                                <div class="cms-activity-list__meta">
                                    <span class="cms-activity-list__actor">{{ $entry['actor'] ?? '' }}</span>
                                    <time class="cms-activity-list__time" datetime="{{ $entry['timestamp'] ?? '' }}">{{ $entry['timestamp'] ?? '' }}</time>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- SEO Health Widget --}}
        <section class="cms-widget" aria-labelledby="widget-seo-health">
            <h2 class="cms-widget__title" id="widget-seo-health">SEO Health</h2>
            <div class="cms-widget__body">
                @php
                    $seoData = $widgets['seo_health']['data'] ?? [];
                    $seoHealthStatus = $seoData['health_status'] ?? 'healthy';
                    $brokenLinkCount = $seoData['broken_link_count'] ?? 0;
                    $sitemapEnabled = $seoData['sitemap_enabled'] ?? true;
                    $lastSitemapGen = $seoData['last_sitemap_generation'] ?? null;
                @endphp
                <div class="cms-health-indicator">
                    <span class="cms-health-indicator__dot cms-health-indicator__dot--{{ $seoHealthStatus }}"
                        aria-hidden="true"></span>
                    <span class="cms-health-indicator__label">
                        @if ($seoHealthStatus === 'healthy')
                            Healthy
                        @elseif ($seoHealthStatus === 'degraded')
                            Degraded
                        @else
                            Unhealthy
                        @endif
                    </span>
                </div>

                <dl class="cms-health-details">
                    <div class="cms-health-details__row">
                        <dt class="cms-health-details__term">Broken Links</dt>
                        <dd class="cms-health-details__value">
                            <span class="cms-health-details__count @if ($brokenLinkCount > 0) cms-health-details__count--warning @endif"
                                aria-label="{{ $brokenLinkCount }} broken links">{{ $brokenLinkCount }}</span>
                        </dd>
                    </div>
                    <div class="cms-health-details__row">
                        <dt class="cms-health-details__term">Sitemap</dt>
                        <dd class="cms-health-details__value">
                            @if ($sitemapEnabled)
                                <span class="cms-badge cms-badge--published" aria-hidden="true"></span>
                                Enabled
                            @else
                                <span class="cms-badge cms-badge--archived" aria-hidden="true"></span>
                                Disabled
                            @endif
                        </dd>
                    </div>
                    @if ($lastSitemapGen !== null)
                        <div class="cms-health-details__row">
                            <dt class="cms-health-details__term">Last Generated</dt>
                            <dd class="cms-health-details__value">
                                <time datetime="{{ $lastSitemapGen }}">{{ $lastSitemapGen }}</time>
                            </dd>
                        </div>
                    @endif
                </dl>

                <a href="/admin/cms/seo/link-health" class="cms-btn cms-btn--outline cms-btn--full">View SEO Details</a>
            </div>
        </section>

        {{-- System Health Widget --}}
        <section class="cms-widget" aria-labelledby="widget-system-health">
            <h2 class="cms-widget__title" id="widget-system-health">System Health</h2>
            <div class="cms-widget__body">
                @php
                    $sysData = $widgets['system_health']['data'] ?? [];
                    $sysHealthStatus = $sysData['health_status'] ?? 'healthy';
                    $cacheHitRate = $sysData['cache_hit_rate'] ?? 0;
                    $cachePercent = round($cacheHitRate * 100);
                    $queueDepth = $sysData['queue_depth'] ?? 0;
                    $failedJobCount = $sysData['failed_job_count'] ?? 0;
                    $storageCount = $sysData['storage_object_count'] ?? 0;
                @endphp
                <div class="cms-health-indicator">
                    <span class="cms-health-indicator__dot cms-health-indicator__dot--{{ $sysHealthStatus }}"
                        aria-hidden="true"></span>
                    <span class="cms-health-indicator__label">
                        @if ($sysHealthStatus === 'healthy')
                            Healthy
                        @elseif ($sysHealthStatus === 'degraded')
                            Degraded
                        @else
                            Unhealthy
                        @endif
                    </span>
                </div>

                <dl class="cms-health-details">
                    <div class="cms-health-details__row">
                        <dt class="cms-health-details__term">Cache Hit Rate</dt>
                        <dd class="cms-health-details__value">
                            <div class="cms-progress-bar" role="meter"
                                aria-valuenow="{{ $cachePercent }}" aria-valuemin="0" aria-valuemax="100"
                                aria-label="Cache hit rate: {{ $cachePercent }}%">
                                <div class="cms-progress-bar__fill
                                    @if ($cachePercent >= 80) cms-progress-bar__fill--good
                                    @elseif ($cachePercent >= 50) cms-progress-bar__fill--warning
                                    @else cms-progress-bar__fill--danger
                                    @endif"
                                    style="width: {{ $cachePercent }}%"></div>
                                <span class="cms-progress-bar__label">{{ $cachePercent }}%</span>
                            </div>
                        </dd>
                    </div>
                    <div class="cms-health-details__row">
                        <dt class="cms-health-details__term">Queue Depth</dt>
                        <dd class="cms-health-details__value" aria-label="{{ $queueDepth }} pending jobs">{{ $queueDepth }}</dd>
                    </div>
                    <div class="cms-health-details__row">
                        <dt class="cms-health-details__term">Storage Objects</dt>
                        <dd class="cms-health-details__value" aria-label="{{ $storageCount }} media objects">{{ $storageCount }}</dd>
                    </div>
                    <div class="cms-health-details__row">
                        <dt class="cms-health-details__term">Failed Jobs</dt>
                        <dd class="cms-health-details__value">
                            <span class="cms-health-details__count @if ($failedJobCount > 0) cms-health-details__count--danger @endif"
                                aria-label="{{ $failedJobCount }} failed jobs">{{ $failedJobCount }}</span>
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        {{-- Pending Reviews --}}
        @can('cms.content.approve')
            <section class="cms-widget" aria-labelledby="widget-reviews">
                <h2 class="cms-widget__title" id="widget-reviews">
                    Pending Reviews
                    @php
                        $reviewData = $widgets['pending_reviews']['data'] ?? [];
                        $reviewCount = $reviewData['count'] ?? 0;
                        $reviewItems = $reviewData['items'] ?? [];
                    @endphp
                    @if ($reviewCount > 0)
                        <span class="cms-widget__badge" aria-label="{{ $reviewCount }} pending reviews">{{ $reviewCount }}</span>
                    @endif
                </h2>
                <div class="cms-widget__body">
                    @if ($reviewCount === 0)
                        <p class="cms-widget__empty">No pending reviews.</p>
                    @else
                        <ul class="cms-review-list" role="list">
                            @foreach ($reviewItems as $review)
                                <li class="cms-review-list__item">
                                    <a href="/admin/cms/reviews" class="cms-review-list__link">
                                        <span class="cms-review-list__content">Content #{{ $review['content_id'] ?? '' }}</span>
                                        <span class="cms-review-list__meta">
                                            by {{ $review['requested_by'] ?? '' }}
                                            &mdash;
                                            <time datetime="{{ $review['created_at'] ?? '' }}">{{ $review['created_at'] ?? '' }}</time>
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
    </div>
</div>
@endsection
