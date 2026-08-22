@extends('admin.layout')

@section('title', 'Rate Limiting Dashboard')

@section('content')
<div class="cms-rate-limits">
    <header class="cms-rate-limits__header">
        <h1 class="cms-rate-limits__title">Rate Limiting</h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    {{-- Summary cards --}}
    <div class="cms-rate-limits__summary">
        <div class="cms-stat-card">
            <div class="cms-stat-card__value">{{ number_format($summary['total_requests'] ?? 0) }}</div>
            <div class="cms-stat-card__label">Total Requests</div>
        </div>
        <div class="cms-stat-card cms-stat-card--danger">
            <div class="cms-stat-card__value">{{ number_format($summary['total_rejections'] ?? 0) }}</div>
            <div class="cms-stat-card__label">Total Rejections</div>
        </div>
        <div class="cms-stat-card @if (($summary['rejection_rate'] ?? 0) > 10) cms-stat-card--warning @endif">
            <div class="cms-stat-card__value">{{ $summary['rejection_rate'] ?? 0 }}%</div>
            <div class="cms-stat-card__label">Rejection Rate</div>
        </div>
    </div>

    {{-- Endpoint configuration table --}}
    <section class="cms-rate-limits__section">
        <h2 class="cms-rate-limits__section-title">Endpoint Configuration</h2>
        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th" scope="col">Endpoint Pattern</th>
                    <th class="cms-table__th" scope="col">Limit</th>
                    <th class="cms-table__th" scope="col">Window (s)</th>
                    <th class="cms-table__th" scope="col">Current Rate</th>
                    <th class="cms-table__th" scope="col">Rejections</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($endpoints))
                    <tr>
                        <td colspan="6" class="cms-table__empty">No rate-limited endpoints configured.</td>
                    </tr>
                @endif

                @foreach ($endpoints as $ep)
                    <tr class="cms-table__row" data-endpoint="{{ $ep['endpoint'] }}">
                        <td class="cms-table__td">
                            <code class="cms-rate-limits__endpoint">{{ $ep['endpoint'] }}</code>
                        </td>
                        <td class="cms-table__td">
                            <input type="number"
                                   class="cms-form-group__input cms-form-group__input--sm cms-rate-limits__limit-input"
                                   name="limit"
                                   value="{{ $ep['limit'] }}"
                                   min="1"
                                   max="10000"
                                   aria-label="Request limit for {{ $ep['endpoint'] }}">
                        </td>
                        <td class="cms-table__td">
                            <input type="number"
                                   class="cms-form-group__input cms-form-group__input--sm cms-rate-limits__window-input"
                                   name="window"
                                   value="{{ $ep['window'] }}"
                                   min="1"
                                   max="86400"
                                   aria-label="Window in seconds for {{ $ep['endpoint'] }}">
                        </td>
                        <td class="cms-table__td">
                            <span class="cms-rate-limits__rate">{{ $ep['current_rate'] ?? 0 }} req/s</span>
                        </td>
                        <td class="cms-table__td">
                            @if (($ep['rejections'] ?? 0) > 0)
                                <span class="cms-badge cms-badge--spam">{{ number_format($ep['rejections'] ?? 0) }}</span>
                            @else
                                <span class="cms-badge cms-badge--approved">0</span>
                            @endif
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            <button type="button"
                                    class="cms-btn cms-btn--sm cms-btn--outline cms-rate-limits__save-btn"
                                    data-endpoint="{{ $ep['endpoint'] }}"
                                    aria-label="Save rate limit for {{ $ep['endpoint'] }}">
                                Save
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Alert threshold configuration --}}
    <section class="cms-rate-limits__section">
        <h2 class="cms-rate-limits__section-title">Alert Threshold Configuration</h2>
        <form method="POST" action="/admin/cms/rate-limits/thresholds" class="cms-form">
            @csrf
            <div class="cms-rate-limits__threshold-grid">
                <div class="cms-form-group">
                    <label for="threshold-rejection-rate" class="cms-form-group__label">Rejection Rate Alert (%)</label>
                    <input type="number"
                           id="threshold-rejection-rate"
                           name="rejection_rate_threshold"
                           value="{{ $thresholds['rejection_rate'] ?? 10 }}"
                           min="1"
                           max="100"
                           class="cms-form-group__input cms-form-group__input--sm"
                           aria-label="Alert when rejection rate exceeds this percentage">
                    <p class="cms-form-group__help">Trigger an alert when the rejection rate exceeds this percentage.</p>
                </div>
                <div class="cms-form-group">
                    <label for="threshold-rejections-per-ip" class="cms-form-group__label">Rejections per IP Alert</label>
                    <input type="number"
                           id="threshold-rejections-per-ip"
                           name="rejections_per_ip_threshold"
                           value="{{ $thresholds['rejections_per_ip'] ?? 100 }}"
                           min="1"
                           max="100000"
                           class="cms-form-group__input cms-form-group__input--sm"
                           aria-label="Alert when a single IP exceeds this many rejections">
                    <p class="cms-form-group__help">Trigger an alert when a single IP exceeds this many rejections in 24 hours.</p>
                </div>
                <div class="cms-form-group">
                    <label for="threshold-notify-email" class="cms-form-group__label">Notification Email</label>
                    <input type="email"
                           id="threshold-notify-email"
                           name="notify_email"
                           value="{{ $thresholds['notify_email'] ?? '' }}"
                           class="cms-form-group__input"
                           placeholder="admin@example.com"
                           aria-label="Email address for rate limit alerts">
                    <p class="cms-form-group__help">Email address to receive rate limit threshold alerts. Leave empty to disable email alerts.</p>
                </div>
            </div>
            <div class="cms-form-group cms-form-group--actions">
                <button type="submit" class="cms-btn cms-btn--primary">Save Thresholds</button>
            </div>
        </form>
    </section>

    {{-- Top IPs --}}
    <section class="cms-rate-limits__section">
        <h2 class="cms-rate-limits__section-title">Top 10 IPs Hitting Limits</h2>
        <table class="cms-table cms-table--compact">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th" scope="col">#</th>
                    <th class="cms-table__th" scope="col">IP Hash</th>
                    <th class="cms-table__th" scope="col">Rejections</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($topIps))
                    <tr>
                        <td colspan="3" class="cms-table__empty">No rate limit rejections recorded yet.</td>
                    </tr>
                @endif

                <?php $__ipRank = 0; ?>
                @foreach ($topIps as $ip)
                    <?php $__ipRank++; ?>
                    <tr class="cms-table__row">
                        <td class="cms-table__td">{{ $__ipRank }}</td>
                        <td class="cms-table__td">
                            <code class="cms-hash">{{ mb_substr($ip['ip_hash'] ?? '', 0, 16) }}...</code>
                        </td>
                        <td class="cms-table__td">
                            <span class="cms-badge cms-badge--spam">{{ number_format($ip['rejections'] ?? 0) }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        var token = csrfToken ? csrfToken.getAttribute('content') : '';

        document.querySelectorAll('.cms-rate-limits__save-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var endpoint = btn.getAttribute('data-endpoint');
                var row = btn.closest('tr');
                if (!row) return;

                var limitInput = row.querySelector('.cms-rate-limits__limit-input');
                var windowInput = row.querySelector('.cms-rate-limits__window-input');
                if (!limitInput || !windowInput) return;

                var limit = parseInt(limitInput.value, 10);
                var windowVal = parseInt(windowInput.value, 10);

                if (isNaN(limit) || limit < 1 || limit > 10000) {
                    alert('Limit must be between 1 and 10,000');
                    return;
                }

                if (isNaN(windowVal) || windowVal < 1 || windowVal > 86400) {
                    alert('Window must be between 1 and 86,400 seconds');
                    return;
                }

                btn.disabled = true;
                btn.textContent = 'Saving...';

                fetch('/admin/cms/rate-limits/update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': token
                    },
                    body: JSON.stringify({
                        endpoint: endpoint,
                        limit: limit,
                        window: windowVal
                    })
                })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data.error) {
                        alert('Error: ' + data.error);
                    } else {
                        btn.textContent = 'Saved';
                        setTimeout(function () {
                            btn.textContent = 'Save';
                        }, 2000);
                    }
                })
                .catch(function () {
                    alert('Failed to save. Please try again.');
                })
                .finally(function () {
                    btn.disabled = false;
                    if (btn.textContent === 'Saving...') {
                        btn.textContent = 'Save';
                    }
                });
            });
        });
    });
</script>
@endsection
