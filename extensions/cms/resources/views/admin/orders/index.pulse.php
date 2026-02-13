@extends('cms::admin.layout')

@section('title', 'Orders')

@section('cms-content')
<div class="cms-content-list">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Orders</h1>
        <div class="cms-content-list__actions">
            @can('cms.commerce.orders.export')
                <a href="/admin/cms/orders/export" class="cms-btn cms-btn--outline">Export Orders</a>
            @endcan
        </div>
    </header>

    <div class="cms-content-list__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/orders" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-status" class="cms-filter-form__label">Status</label>
                <select id="filter-status" name="status" class="cms-filter-form__select">
                    <option value="">All Statuses</option>
                    <option value="cart" @if (($filters['status'] ?? '') === 'cart') selected @endif>Cart</option>
                    <option value="pending_payment" @if (($filters['status'] ?? '') === 'pending_payment') selected @endif>Pending Payment</option>
                    <option value="confirmed" @if (($filters['status'] ?? '') === 'confirmed') selected @endif>Confirmed</option>
                    <option value="fulfilled" @if (($filters['status'] ?? '') === 'fulfilled') selected @endif>Fulfilled</option>
                    <option value="refunded" @if (($filters['status'] ?? '') === 'refunded') selected @endif>Refunded</option>
                    <option value="cancelled" @if (($filters['status'] ?? '') === 'cancelled') selected @endif>Cancelled</option>
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-payment" class="cms-filter-form__label">Payment Status</label>
                <select id="filter-payment" name="payment_status" class="cms-filter-form__select">
                    <option value="">All</option>
                    <option value="pending" @if (($filters['payment_status'] ?? '') === 'pending') selected @endif>Pending</option>
                    <option value="paid" @if (($filters['payment_status'] ?? '') === 'paid') selected @endif>Paid</option>
                    <option value="failed" @if (($filters['payment_status'] ?? '') === 'failed') selected @endif>Failed</option>
                    <option value="refunded" @if (($filters['payment_status'] ?? '') === 'refunded') selected @endif>Refunded</option>
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-date-from" class="cms-filter-form__label">From</label>
                <input type="date" id="filter-date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="cms-filter-form__input">
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-date-to" class="cms-filter-form__label">To</label>
                <input type="date" id="filter-date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="cms-filter-form__input">
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-search" class="cms-filter-form__label">Search</label>
                <input type="search"
                       id="filter-search"
                       name="search"
                       value="{{ $filters['search'] ?? '' }}"
                       class="cms-filter-form__input"
                       placeholder="Order # or email">
            </div>

            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">Order #</th>
                <th class="cms-table__th" scope="col">Date</th>
                <th class="cms-table__th" scope="col">Customer</th>
                <th class="cms-table__th" scope="col">Subtotal</th>
                <th class="cms-table__th" scope="col">Tax</th>
                <th class="cms-table__th" scope="col">Discount</th>
                <th class="cms-table__th" scope="col">Total</th>
                <th class="cms-table__th" scope="col">Status</th>
                <th class="cms-table__th" scope="col">Payment</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($orders))
                <tr>
                    <td colspan="10" class="cms-table__empty">No orders found.</td>
                </tr>
            @endif

            @foreach ($orders as $order)
                <?php
                $__currency = strtoupper($order['currency'] ?? 'USD');
                ?>
                <tr class="cms-table__row">
                    <td class="cms-table__td">
                        <a href="/admin/cms/orders/{{ $order['id'] }}" class="cms-content-list__link">
                            {{ $order['order_number'] ?? '' }}
                        </a>
                    </td>
                    <td class="cms-table__td">
                        <time datetime="{{ $order['created_at'] ?? '' }}">{{ $order['created_at_human'] ?? $order['created_at'] ?? '' }}</time>
                    </td>
                    <td class="cms-table__td" title="{{ $order['customer_email'] ?? '' }}">
                        <?php
                        $__email = $order['customer_email'] ?? '';
                echo htmlspecialchars(strlen($__email) > 25 ? substr($__email, 0, 22) . '...' : $__email, ENT_QUOTES, 'UTF-8');
                ?>
                    </td>
                    <td class="cms-table__td">{{ number_format(($order['subtotal'] ?? 0) / 100, 2) }} {{ $__currency }}</td>
                    <td class="cms-table__td">{{ number_format(($order['tax_amount'] ?? 0) / 100, 2) }} {{ $__currency }}</td>
                    <td class="cms-table__td">{{ number_format(($order['discount_amount'] ?? 0) / 100, 2) }} {{ $__currency }}</td>
                    <td class="cms-table__td"><strong>{{ number_format(($order['total'] ?? 0) / 100, 2) }} {{ $__currency }}</strong></td>
                    <td class="cms-table__td">
                        @include('cms::admin._partials.status-badge', ['status' => $order['status'] ?? 'cart'])
                    </td>
                    <td class="cms-table__td">
                        @include('cms::admin._partials.status-badge', ['status' => $order['payment_status'] ?? 'pending'])
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Order actions">
                            <a href="/admin/cms/orders/{{ $order['id'] }}" class="cms-btn cms-btn--sm cms-btn--outline" title="View">View</a>
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
        'baseUrl' => '/admin/cms/orders',
    ])
</div>
@endsection
