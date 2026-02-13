@extends('admin.layout')

@section('title', 'Newsletter Campaigns')

@section('content')
<div class="cms-newsletter-campaigns">
    <header class="cms-newsletter-campaigns__header">
        <h1 class="cms-newsletter-campaigns__title">Newsletter Campaigns</h1>
        <div class="cms-newsletter-campaigns__actions">
            <a href="/admin/cms/newsletter/subscribers" class="cms-btn cms-btn--outline">Subscribers</a>
            @can('cms.newsletter.manage')
                <a href="/admin/cms/newsletter/campaigns/create" class="cms-btn cms-btn--primary">Create Campaign</a>
            @endcan
        </div>
    </header>

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="subject">Subject</th>
                <th class="cms-table__th" scope="col">Status</th>
                <th class="cms-table__th" scope="col">Locale</th>
                <th class="cms-table__th" scope="col">Recipients</th>
                <th class="cms-table__th" scope="col">Open Rate</th>
                <th class="cms-table__th" scope="col">Click Rate</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="sent_at">Scheduled / Sent At</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($campaigns))
                <tr>
                    <td colspan="8" class="cms-table__empty">No campaigns found. Create your first campaign to get started.</td>
                </tr>
            @endif

            @foreach ($campaigns as $campaign)
                <?php
                $__recipientCount = max(1, $campaign['recipient_count'] ?? 1);
                $__openRate = ($campaign['recipient_count'] ?? 0) > 0
                    ? round(($campaign['opened_count'] ?? 0) / $__recipientCount * 100, 1)
                    : 0;
                $__clickRate = ($campaign['recipient_count'] ?? 0) > 0
                    ? round(($campaign['clicked_count'] ?? 0) / $__recipientCount * 100, 1)
                    : 0;
                ?>
                <tr class="cms-table__row">
                    <td class="cms-table__td">
                        <a href="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/edit" class="cms-newsletter-campaigns__subject-link">
                            {{ $campaign['subject'] }}
                        </a>
                    </td>
                    <td class="cms-table__td">
                        <?php
                        $__campaignBadgeClass = match ($campaign['status'] ?? '') {
                            'draft' => 'cms-badge cms-badge--draft',
                            'scheduled' => 'cms-badge cms-badge--scheduled',
                            'sending' => 'cms-badge cms-badge--in-review',
                            'sent' => 'cms-badge cms-badge--approved',
                            'cancelled' => 'cms-badge cms-badge--archived',
                            default => 'cms-badge',
                        };
                ?>
                        <span class="{{ $__campaignBadgeClass }}" role="status">{{ ucfirst($campaign['status'] ?? '') }}</span>
                    </td>
                    <td class="cms-table__td">
                        <span class="cms-badge">{{ strtoupper($campaign['locale'] ?? '') }}</span>
                    </td>
                    <td class="cms-table__td">{{ number_format($campaign['recipient_count'] ?? 0) }}</td>
                    <td class="cms-table__td">
                        @if (($campaign['status'] ?? '') === 'sent')
                            {{ $__openRate }}%
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @if (($campaign['status'] ?? '') === 'sent')
                            {{ $__clickRate }}%
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @if (isset($campaign['sent_at']) && $campaign['sent_at'] !== null)
                            <time datetime="{{ $campaign['sent_at'] }}">{{ $campaign['sent_at_human'] ?? $campaign['sent_at'] }}</time>
                        @elseif (isset($campaign['scheduled_at']) && $campaign['scheduled_at'] !== null)
                            <time datetime="{{ $campaign['scheduled_at'] }}">{{ $campaign['scheduled_at_human'] ?? $campaign['scheduled_at'] }}</time>
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Campaign actions">
                            @if (in_array($campaign['status'] ?? '', ['sent', 'sending'], true))
                                <a href="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/analytics" class="cms-btn cms-btn--sm cms-btn--outline">Analytics</a>
                            @endif
                            @can('cms.newsletter.manage')
                                @if (($campaign['status'] ?? '') === 'draft')
                                    <a href="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/edit" class="cms-btn cms-btn--sm cms-btn--outline">Edit</a>
                                    <form method="POST" action="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/send" class="cms-inline-form" data-cms-confirm="Send this campaign to all matching subscribers?">
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--primary">Send Now</button>
                                    </form>
                                @endif
                                @if (($campaign['status'] ?? '') === 'scheduled')
                                    <form method="POST" action="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/cancel" class="cms-inline-form">
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Cancel</button>
                                    </form>
                                @endif
                                <form method="POST" action="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/duplicate" class="cms-inline-form">
                                    @csrf
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--outline">Duplicate</button>
                                </form>
                                @if (in_array($campaign['status'] ?? '', ['draft', 'cancelled'], true))
                                    <form method="POST" action="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/delete" class="cms-inline-form" data-cms-confirm="Delete this campaign? This cannot be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Delete</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 20,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/newsletter/campaigns',
    ])
</div>
@endsection
