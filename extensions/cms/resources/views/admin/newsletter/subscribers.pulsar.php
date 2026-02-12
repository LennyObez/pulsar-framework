@extends('admin.layout')

@section('title', 'Newsletter Subscribers')

@section('content')
<div class="cms-newsletter-subscribers">
    <header class="cms-newsletter-subscribers__header">
        <h1 class="cms-newsletter-subscribers__title">
            Newsletter Subscribers
            @if (($statusCounts['confirmed'] ?? 0) > 0)
                <span class="cms-widget__badge" aria-label="{{ $statusCounts['confirmed'] }} confirmed subscribers">{{ $statusCounts['confirmed'] }} confirmed</span>
            @endif
        </h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    {{-- Status tabs --}}
    <nav class="cms-newsletter-subscribers__tabs" aria-label="Subscriber status filter">
        <ul class="cms-newsletter-subscribers__tab-list" role="tablist">
            <?php
            $__statusTabs = [
                'confirmed' => 'Confirmed',
                'pending' => 'Pending',
                'unsubscribed' => 'Unsubscribed',
            ];
            ?>
            @foreach ($__statusTabs as $tabValue => $tabLabel)
                <li role="presentation">
                    <a href="/admin/cms/newsletter/subscribers?status={{ $tabValue }}"
                       class="cms-newsletter-subscribers__tab @if (($activeStatus ?? 'confirmed') === $tabValue) cms-newsletter-subscribers__tab--active @endif"
                       role="tab"
                       aria-selected="{{ ($activeStatus ?? 'confirmed') === $tabValue ? 'true' : 'false' }}">
                        {{ $tabLabel }}
                        @if (isset($statusCounts[$tabValue]) && $statusCounts[$tabValue] > 0)
                            <span class="cms-newsletter-subscribers__tab-count" aria-label="{{ $statusCounts[$tabValue] }} {{ $tabLabel }}">{{ $statusCounts[$tabValue] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Filters --}}
    <div class="cms-newsletter-subscribers__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/newsletter/subscribers" class="cms-filter-form">
            <input type="hidden" name="status" value="{{ $activeStatus ?? 'confirmed' }}">
            <div class="cms-filter-form__group">
                <label for="filter-search" class="cms-filter-form__label">Search</label>
                <input type="text" id="filter-search" name="search" value="{{ $filters['search'] ?? '' }}" class="cms-filter-form__input" placeholder="Search by email...">
            </div>
            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    {{-- Bulk actions --}}
    <form method="POST" action="/admin/cms/newsletter/subscribers/bulk" class="cms-newsletter-subscribers__bulk" data-cms-bulk-form>
        @csrf

        <div class="cms-newsletter-subscribers__bulk-actions">
            <select name="bulk_action" class="cms-filter-form__select" aria-label="Bulk action">
                <option value="">Bulk Actions</option>
                @can('cms.newsletter.manage')
                    <option value="delete">Delete Selected</option>
                    <option value="unsubscribe">Unsubscribe Selected</option>
                @endcan
            </select>
            <button type="submit" class="cms-btn cms-btn--outline" data-cms-bulk-submit>Apply</button>
        </div>

        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th cms-table__th--checkbox" scope="col">
                        <input type="checkbox" aria-label="Select all" data-cms-select-all>
                    </th>
                    <th class="cms-table__th" scope="col">Email</th>
                    <th class="cms-table__th" scope="col">Locale</th>
                    <th class="cms-table__th" scope="col">Source</th>
                    <th class="cms-table__th" scope="col">Status</th>
                    <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="created_at">Subscribed</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($subscribers))
                    <tr>
                        <td colspan="7" class="cms-table__empty">No subscribers found matching the current filters.</td>
                    </tr>
                @endif

                @foreach ($subscribers as $subscriber)
                    <tr class="cms-table__row">
                        <td class="cms-table__td cms-table__td--checkbox">
                            <input type="checkbox" name="ids[]" value="{{ $subscriber['id'] }}" aria-label="Select {{ $subscriber['email'] }}">
                        </td>
                        <td class="cms-table__td">
                            <a href="/admin/cms/newsletter/subscribers/{{ $subscriber['id'] }}" class="cms-newsletter-subscribers__email-link">
                                {{ $subscriber['email'] }}
                            </a>
                        </td>
                        <td class="cms-table__td">
                            <span class="cms-badge">{{ $subscriber['locale'] }}</span>
                        </td>
                        <td class="cms-table__td">{{ $subscriber['source'] }}</td>
                        <td class="cms-table__td">
                            <?php
                            $__subscriberBadgeClass = match ($subscriber['status'] ?? '') {
                                'confirmed' => 'cms-badge cms-badge--approved',
                                'pending' => 'cms-badge cms-badge--in-review',
                                'unsubscribed' => 'cms-badge cms-badge--archived',
                                default => 'cms-badge',
                            };
            ?>
                            <span class="{{ $__subscriberBadgeClass }}" role="status">{{ ucfirst($subscriber['status'] ?? '') }}</span>
                        </td>
                        <td class="cms-table__td">
                            <time datetime="{{ $subscriber['created_at'] }}">{{ $subscriber['created_at'] }}</time>
                        </td>
                        <td class="cms-table__td cms-table__td--actions">
                            <div class="cms-action-group" role="group" aria-label="Subscriber actions">
                                <a href="/admin/cms/newsletter/subscribers/{{ $subscriber['id'] }}" class="cms-btn cms-btn--sm cms-btn--outline">View</a>
                                @can('cms.newsletter.manage')
                                    @if ($subscriber['status'] === 'confirmed')
                                        <form method="POST" action="/admin/cms/newsletter/subscribers/{{ $subscriber['id'] }}/unsubscribe" class="cms-inline-form">
                                            @csrf
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Unsubscribe</button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
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
        'baseUrl' => '/admin/cms/newsletter/subscribers?status=' . ($activeStatus ?? 'confirmed'),
    ])
</div>
@endsection
