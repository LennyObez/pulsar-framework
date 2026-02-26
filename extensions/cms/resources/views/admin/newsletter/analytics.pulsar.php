@extends('admin.layout')

@section('title', 'Campaign Analytics')

@section('content')
<div class="cms-newsletter-analytics">
    <header class="cms-newsletter-analytics__header">
        <h1 class="cms-newsletter-analytics__title">Campaign Analytics</h1>
        <a href="/admin/cms/newsletter/campaigns" class="cms-btn cms-btn--outline">Back to Campaigns</a>
    </header>

    {{-- Campaign summary --}}
    <div class="cms-newsletter-analytics__summary">
        <h2 class="cms-newsletter-analytics__subject">{{ $campaign['subject'] ?? '' }}</h2>
        <div class="cms-newsletter-analytics__meta">
            <?php
            $__analyticsBadgeClass = match ($campaign['status'] ?? '') {
                'sent' => 'cms-badge cms-badge--approved',
                'sending' => 'cms-badge cms-badge--in-review',
                default => 'cms-badge',
            };
            ?>
            <span class="{{ $__analyticsBadgeClass }}" role="status">{{ ucfirst($campaign['status'] ?? '') }}</span>
            @if (isset($campaign['sent_at']))
                <span class="cms-newsletter-analytics__sent-at">
                    Sent: <time datetime="{{ $campaign['sent_at'] }}">{{ $campaign['sent_at'] }}</time>
                </span>
            @endif
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="cms-newsletter-analytics__cards">
        <div class="cms-widget" aria-label="Recipients">
            <div class="cms-widget__value">{{ $analytics['recipient_count'] ?? 0 }}</div>
            <div class="cms-widget__label">Recipients</div>
        </div>

        <div class="cms-widget" aria-label="Delivered">
            <div class="cms-widget__value">{{ $analytics['delivered_count'] ?? 0 }}</div>
            <div class="cms-widget__label">Delivered</div>
        </div>

        <div class="cms-widget" aria-label="Open Rate">
            <div class="cms-widget__value">{{ $analytics['open_rate'] ?? 0 }}%</div>
            <div class="cms-widget__label">Open Rate</div>
            <div class="cms-widget__detail">{{ $analytics['opened_count'] ?? 0 }} opens</div>
        </div>

        <div class="cms-widget" aria-label="Click Rate">
            <div class="cms-widget__value">{{ $analytics['click_rate'] ?? 0 }}%</div>
            <div class="cms-widget__label">Click Rate</div>
            <div class="cms-widget__detail">{{ $analytics['clicked_count'] ?? 0 }} clicks</div>
        </div>

        <div class="cms-widget" aria-label="Bounce Rate">
            <div class="cms-widget__value">{{ $analytics['bounce_rate'] ?? 0 }}%</div>
            <div class="cms-widget__label">Bounce Rate</div>
            <div class="cms-widget__detail">{{ $analytics['bounced_count'] ?? 0 }} bounces</div>
        </div>

        <div class="cms-widget" aria-label="Failed">
            <div class="cms-widget__value">{{ $analytics['failed_count'] ?? 0 }}</div>
            <div class="cms-widget__label">Failed</div>
        </div>
    </div>

    {{-- Delivery breakdown --}}
    <div class="cms-newsletter-analytics__breakdown">
        <h3 class="cms-newsletter-analytics__breakdown-title">Delivery Breakdown</h3>
        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th" scope="col">Status</th>
                    <th class="cms-table__th" scope="col">Count</th>
                    <th class="cms-table__th" scope="col">Percentage</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                <?php
                $__totalRecipients = max(1, $analytics['recipient_count'] ?? 1);
            $__breakdownRows = [
                ['Sent', $analytics['sent_count'] ?? 0, 'cms-badge--in-review'],
                ['Delivered', $analytics['delivered_count'] ?? 0, 'cms-badge--approved'],
                ['Opened', $analytics['opened_count'] ?? 0, 'cms-badge--approved'],
                ['Clicked', $analytics['clicked_count'] ?? 0, 'cms-badge--approved'],
                ['Bounced', $analytics['bounced_count'] ?? 0, 'cms-badge--spam'],
                ['Failed', $analytics['failed_count'] ?? 0, 'cms-badge--archived'],
            ];
            ?>
                @foreach ($__breakdownRows as $__row)
                    <tr class="cms-table__row">
                        <td class="cms-table__td">
                            <span class="cms-badge {{ $__row[2] }}">{{ $__row[0] }}</span>
                        </td>
                        <td class="cms-table__td">{{ $__row[1] }}</td>
                        <td class="cms-table__td">{{ round(($__row[1] / $__totalRecipients) * 100, 1) }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
