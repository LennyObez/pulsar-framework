@extends('cms::admin.layout')

@section('title', 'Export Orders')

@section('cms-content')
<div class="cms-content-form">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Export Orders</h1>
        <div class="cms-content-list__actions">
            <a href="/admin/cms/orders" class="cms-btn cms-btn--outline">Back to Orders</a>
        </div>
    </header>

    <form method="GET" action="/admin/cms/orders/export" class="cms-content-form__form">
        <div class="cms-content-form__main">
            <div class="cms-form-group">
                <label for="export-format" class="cms-form-group__label">Format <span class="cms-required" aria-label="required">*</span></label>
                <select id="export-format" name="format" class="cms-form-group__select" required>
                    <option value="csv">CSV</option>
                    <option value="json">JSON</option>
                </select>
            </div>

            <fieldset class="cms-fieldset">
                <legend class="cms-fieldset__legend">Date Range</legend>

                <div class="cms-form-group">
                    <label for="export-date-from" class="cms-form-group__label">From</label>
                    <input type="date"
                           id="export-date-from"
                           name="date_from"
                           class="cms-form-group__input">
                </div>

                <div class="cms-form-group">
                    <label for="export-date-to" class="cms-form-group__label">To</label>
                    <input type="date"
                           id="export-date-to"
                           name="date_to"
                           class="cms-form-group__input">
                </div>
            </fieldset>

            <div class="cms-form-group">
                <label for="export-status" class="cms-form-group__label">Status Filter</label>
                <select id="export-status" name="status[]" class="cms-form-group__select" multiple size="5">
                    <option value="cart">Cart</option>
                    <option value="pending_payment">Pending Payment</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="fulfilled">Fulfilled</option>
                    <option value="refunded">Refunded</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <span class="cms-form-group__hint">Hold Ctrl/Cmd to select multiple. Leave empty for all statuses.</span>
            </div>

            <div class="cms-form-group">
                <label class="cms-form-group__label">
                    <input type="checkbox"
                           name="include_pii"
                           value="1"
                           class="cms-form-group__checkbox"
                           data-cms-pii-toggle>
                    Include PII (personally identifiable information)
                </label>
                <div class="cms-alert cms-alert--warning" role="alert">
                    <strong>Warning:</strong> Including PII requires step-up authentication and the exported file will contain sensitive customer data. Handle according to your data protection policy.
                </div>
            </div>

            <button type="submit" class="cms-btn cms-btn--primary">Export</button>
        </div>
    </form>
</div>
@endsection
