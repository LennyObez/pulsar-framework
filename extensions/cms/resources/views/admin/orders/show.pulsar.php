@extends('cms::admin.layout')

@section('title', 'Order #' . ($order['order_number'] ?? ''))

@section('cms-content')
<?php
$__currency = strtoupper($order['currency'] ?? 'USD');
$__statuses = ['cart', 'pending_payment', 'confirmed', 'fulfilled'];
$__currentIndex = array_search($order['status'] ?? 'cart', $__statuses, true);
if ($__currentIndex === false) {
    $__currentIndex = -1;
}
?>
<div class="cms-content-show">
    <header class="cms-content-show__header">
        <div class="cms-content-show__meta">
            <h1 class="cms-content-show__title">Order #{{ $order['order_number'] ?? '' }}</h1>
            @include('cms::admin._partials.status-badge', ['status' => $order['status'] ?? 'cart'])
        </div>
        <div class="cms-content-show__actions">
            <a href="/admin/cms/orders" class="cms-btn cms-btn--outline">Back to Orders</a>
        </div>
    </header>

    {{-- Status Timeline --}}
    <section class="cms-order-timeline" aria-label="Order status timeline">
        <ol class="cms-order-timeline__steps">
            @foreach ($__statuses as $stepIndex => $step)
                <li class="cms-order-timeline__step @if ($stepIndex < $__currentIndex) cms-order-timeline__step--completed @elseif ($stepIndex === $__currentIndex) cms-order-timeline__step--current @endif">
                    <span class="cms-order-timeline__marker">{{ $stepIndex + 1 }}</span>
                    <span class="cms-order-timeline__label">{{ ucwords(str_replace('_', ' ', $step)) }}</span>
                </li>
            @endforeach
        </ol>
    </section>

    <div class="cms-content-show__layout">
        <article class="cms-content-show__body">
            {{-- Customer Info --}}
            <section class="cms-card">
                <h2 class="cms-card__title">Customer Information</h2>
                <dl class="cms-detail-list">
                    <dt class="cms-detail-list__term">Email</dt>
                    <dd class="cms-detail-list__value">{{ $order['customer_email'] ?? '' }}</dd>
                </dl>

                <div class="cms-card__columns">
                    <div class="cms-card__column">
                        <h3 class="cms-card__subtitle">Billing Address</h3>
                        @if (!empty($order['billing_address']))
                            <?php $__billing = $order['billing_address']; ?>
                            <address class="cms-address">
                                {{ $__billing['name'] ?? '' }}<br>
                                {{ $__billing['line1'] ?? '' }}<br>
                                @if (!empty($__billing['line2']))
                                    {{ $__billing['line2'] }}<br>
                                @endif
                                {{ $__billing['city'] ?? '' }}, {{ $__billing['postal_code'] ?? '' }}<br>
                                {{ $__billing['country'] ?? '' }}
                            </address>
                        @else
                            <p class="cms-text--muted">No billing address provided.</p>
                        @endif
                    </div>
                    <div class="cms-card__column">
                        <h3 class="cms-card__subtitle">Shipping Address</h3>
                        @if (!empty($order['shipping_address']))
                            <?php $__shipping = $order['shipping_address']; ?>
                            <address class="cms-address">
                                {{ $__shipping['name'] ?? '' }}<br>
                                {{ $__shipping['line1'] ?? '' }}<br>
                                @if (!empty($__shipping['line2']))
                                    {{ $__shipping['line2'] }}<br>
                                @endif
                                {{ $__shipping['city'] ?? '' }}, {{ $__shipping['postal_code'] ?? '' }}<br>
                                {{ $__shipping['country'] ?? '' }}
                            </address>
                        @else
                            <p class="cms-text--muted">No shipping address provided.</p>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Order Items --}}
            <section class="cms-card">
                <h2 class="cms-card__title">Order Items</h2>
                <table class="cms-table">
                    <thead class="cms-table__head">
                        <tr>
                            <th class="cms-table__th">Product</th>
                            <th class="cms-table__th">Variant</th>
                            <th class="cms-table__th">Qty</th>
                            <th class="cms-table__th">Unit Price</th>
                            <th class="cms-table__th">Discount</th>
                            <th class="cms-table__th">Tax</th>
                            <th class="cms-table__th">Total</th>
                        </tr>
                    </thead>
                    <tbody class="cms-table__body">
                        @foreach ($items ?? [] as $item)
                            <tr class="cms-table__row">
                                <td class="cms-table__td">{{ $item['product_snapshot']['name'] ?? $item['product_id'] ?? '' }}</td>
                                <td class="cms-table__td">{{ $item['variant_id'] ?? '-' }}</td>
                                <td class="cms-table__td">{{ $item['quantity'] ?? 0 }}</td>
                                <td class="cms-table__td">{{ number_format(($item['unit_price'] ?? 0) / 100, 2) }} {{ $__currency }}</td>
                                <td class="cms-table__td">{{ number_format(($item['discount_amount'] ?? 0) / 100, 2) }} {{ $__currency }}</td>
                                <td class="cms-table__td">{{ number_format(($item['tax_amount'] ?? 0) / 100, 2) }} {{ $__currency }}</td>
                                <td class="cms-table__td"><strong>{{ number_format(($item['total_price'] ?? 0) / 100, 2) }} {{ $__currency }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            {{-- Order Summary --}}
            <section class="cms-card">
                <h2 class="cms-card__title">Order Summary</h2>
                <dl class="cms-detail-list cms-detail-list--summary">
                    <dt class="cms-detail-list__term">Subtotal</dt>
                    <dd class="cms-detail-list__value">{{ number_format(($order['subtotal'] ?? 0) / 100, 2) }} {{ $__currency }}</dd>

                    <dt class="cms-detail-list__term">Discount</dt>
                    <dd class="cms-detail-list__value">-{{ number_format(($order['discount_amount'] ?? 0) / 100, 2) }} {{ $__currency }}</dd>

                    <dt class="cms-detail-list__term">Tax</dt>
                    <dd class="cms-detail-list__value">{{ number_format(($order['tax_amount'] ?? 0) / 100, 2) }} {{ $__currency }}</dd>

                    <dt class="cms-detail-list__term cms-detail-list__term--total">Total</dt>
                    <dd class="cms-detail-list__value cms-detail-list__value--total"><strong>{{ number_format(($order['total'] ?? 0) / 100, 2) }} {{ $__currency }}</strong></dd>
                </dl>
            </section>

            {{-- Payment Info --}}
            <section class="cms-card">
                <h2 class="cms-card__title">Payment Information</h2>
                <dl class="cms-detail-list">
                    <dt class="cms-detail-list__term">Payment Intent ID</dt>
                    <dd class="cms-detail-list__value"><code>{{ $order['payment_intent_id'] ?? '-' }}</code></dd>
                    <dt class="cms-detail-list__term">Payment Status</dt>
                    <dd class="cms-detail-list__value">
                        @include('cms::admin._partials.status-badge', ['status' => $order['payment_status'] ?? 'pending'])
                    </dd>
                </dl>
            </section>

            {{-- Invoice --}}
            @if (isset($invoice))
                <section class="cms-card">
                    <h2 class="cms-card__title">Invoice</h2>
                    <a href="/admin/cms/orders/{{ $order['id'] }}/invoice/download" class="cms-btn cms-btn--outline">
                        Download Invoice
                    </a>
                </section>
            @endif

            {{-- Refund --}}
            @can('cms.commerce.orders.refund')
                <section class="cms-card">
                    <h2 class="cms-card__title">Issue Refund</h2>
                    <form method="POST" action="/admin/cms/orders/{{ $order['id'] }}/refund" class="cms-refund-form">
                        @csrf
                        <div class="cms-form-group">
                            <label for="refund-amount" class="cms-form-group__label">Amount (minor units) <span class="cms-required" aria-label="required">*</span></label>
                            <input type="number"
                                   id="refund-amount"
                                   name="amount"
                                   class="cms-form-group__input"
                                   min="1"
                                   max="{{ $order['total'] ?? 0 }}"
                                   required>
                        </div>

                        <div class="cms-form-group">
                            <label for="refund-reason" class="cms-form-group__label">Reason <span class="cms-required" aria-label="required">*</span></label>
                            <textarea id="refund-reason"
                                      name="reason"
                                      class="cms-form-group__textarea"
                                      rows="3"
                                      required
                                      minlength="5"></textarea>
                        </div>

                        <button type="submit" class="cms-btn cms-btn--danger" data-cms-step-up>Issue Refund</button>
                    </form>
                </section>
            @endcan

            {{-- Admin Notes --}}
            <section class="cms-card">
                <h2 class="cms-card__title">Admin Notes</h2>
                @if (!empty($order['notes']))
                    <div class="cms-card__body">
                        <p>{{ $order['notes'] }}</p>
                    </div>
                @else
                    <p class="cms-text--muted">No notes for this order.</p>
                @endif
            </section>
        </article>
    </div>
</div>

@include('cms::admin._partials.step-up-prompt')
@endsection
