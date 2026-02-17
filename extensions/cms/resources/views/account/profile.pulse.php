@extends('account.layout')

@section('title', @t('account.profile'))

@section('content')
<h1 class="pui-heading pui-heading--xl">@t('account.profile')</h1>

@if (isset($_GET['saved']))
    <div class="pui-alert pui-alert--success pui-mb-4" role="alert">
        @t('account.profile_saved')
    </div>
@endif

<form method="POST" action="/account/profile">
    @csrf

    <div class="pui-card pui-mb-6">
        <div class="pui-card__header">@t('account.personal_info')</div>
        <div class="pui-card__body">
            <div class="pui-grid pui-grid--cols-2 pui-gap-4">
                <div class="pui-form-group">
                    <label for="display_name" class="pui-form-group__label">@t('account.display_name')</label>
                    <input type="text" id="display_name" name="display_name" value="{{ $customer['display_name'] ?? '' }}" class="pui-input" required>
                </div>
                <div class="pui-form-group">
                    <label for="email" class="pui-form-group__label">@t('account.email')</label>
                    <input type="email" id="email" value="{{ $customer['email'] ?? '' }}" class="pui-input" disabled>
                    <span class="pui-form-group__help">@t('account.email_change_note')</span>
                </div>
            </div>
        </div>
    </div>

    <div class="pui-card pui-mb-6">
        <div class="pui-card__header">@t('account.billing_address')</div>
        <div class="pui-card__body">
            <div class="pui-grid pui-grid--cols-2 pui-gap-4">
                <div class="pui-form-group pui-col-span-2">
                    <label for="billing_line1" class="pui-form-group__label">@t('account.address_line1')</label>
                    <input type="text" id="billing_line1" name="billing_address[line1]" value="{{ $customer['billing_address']['line1'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="billing_city" class="pui-form-group__label">@t('account.city')</label>
                    <input type="text" id="billing_city" name="billing_address[city]" value="{{ $customer['billing_address']['city'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="billing_postal" class="pui-form-group__label">@t('account.postal_code')</label>
                    <input type="text" id="billing_postal" name="billing_address[postalCode]" value="{{ $customer['billing_address']['postalCode'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="billing_region" class="pui-form-group__label">@t('account.region')</label>
                    <input type="text" id="billing_region" name="billing_address[region]" value="{{ $customer['billing_address']['region'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="billing_country" class="pui-form-group__label">@t('account.country')</label>
                    <input type="text" id="billing_country" name="billing_address[country]" value="{{ $customer['billing_address']['country'] ?? '' }}" class="pui-input">
                </div>
            </div>
        </div>
    </div>

    <div class="pui-card pui-mb-6">
        <div class="pui-card__header">@t('account.shipping_address')</div>
        <div class="pui-card__body">
            <div class="pui-grid pui-grid--cols-2 pui-gap-4">
                <div class="pui-form-group pui-col-span-2">
                    <label for="shipping_line1" class="pui-form-group__label">@t('account.address_line1')</label>
                    <input type="text" id="shipping_line1" name="shipping_address[line1]" value="{{ $customer['shipping_address']['line1'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="shipping_city" class="pui-form-group__label">@t('account.city')</label>
                    <input type="text" id="shipping_city" name="shipping_address[city]" value="{{ $customer['shipping_address']['city'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="shipping_postal" class="pui-form-group__label">@t('account.postal_code')</label>
                    <input type="text" id="shipping_postal" name="shipping_address[postalCode]" value="{{ $customer['shipping_address']['postalCode'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="shipping_region" class="pui-form-group__label">@t('account.region')</label>
                    <input type="text" id="shipping_region" name="shipping_address[region]" value="{{ $customer['shipping_address']['region'] ?? '' }}" class="pui-input">
                </div>
                <div class="pui-form-group">
                    <label for="shipping_country" class="pui-form-group__label">@t('account.country')</label>
                    <input type="text" id="shipping_country" name="shipping_address[country]" value="{{ $customer['shipping_address']['country'] ?? '' }}" class="pui-input">
                </div>
            </div>
        </div>
    </div>

    <div class="pui-cluster pui-cluster--end">
        <button type="submit" class="pui-btn pui-btn--primary">@t('account.save_changes')</button>
    </div>
</form>
@endsection
