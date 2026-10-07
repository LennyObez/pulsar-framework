@extends('account.layout')

@section('title', @t('account.settings'))

@section('content')
<h1 class="pui-heading pui-heading--xl">@t('account.settings')</h1>

<div class="pui-card pui-mb-6">
    <div class="pui-card__header">@t('account.change_password')</div>
    <div class="pui-card__body">
        <form method="POST" action="/account/settings/password">
            @csrf
            <div class="pui-stack pui-stack--md" data-max-width="24rem">
                <div class="pui-form-group">
                    <label for="current_password" class="pui-form-group__label">@t('account.current_password')</label>
                    <input type="password" id="current_password" name="current_password" class="pui-input" required autocomplete="current-password">
                </div>
                <div class="pui-form-group">
                    <label for="new_password" class="pui-form-group__label">@t('account.new_password')</label>
                    <input type="password" id="new_password" name="new_password" class="pui-input" required autocomplete="new-password" minlength="12">
                </div>
                <div class="pui-form-group">
                    <label for="confirm_password" class="pui-form-group__label">@t('account.confirm_password')</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="pui-input" required autocomplete="new-password">
                </div>
                <div>
                    <button type="submit" class="pui-btn pui-btn--primary">@t('account.update_password')</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="pui-card pui-mb-6">
    <div class="pui-card__header">@t('account.two_factor_auth')</div>
    <div class="pui-card__body">
        <p class="pui-card__text pui-mb-4">@t('account.two_factor_description')</p>
        <a href="/account/settings/2fa" class="pui-btn pui-btn--outline">@t('account.manage_2fa')</a>
    </div>
</div>

<div class="pui-card pui-card--danger">
    <div class="pui-card__header">@t('account.danger_zone')</div>
    <div class="pui-card__body">
        <p class="pui-card__text pui-mb-4">@t('account.delete_account_warning')</p>
        <button type="button" class="pui-btn pui-btn--danger" data-confirm="@t('account.confirm_delete')">@t('account.delete_account')</button>
    </div>
</div>
@endsection
