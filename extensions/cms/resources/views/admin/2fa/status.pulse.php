@extends('admin.layout')

@section('title', '2FA Settings')

@section('content')
<div class="cms-2fa-status">
    <header class="cms-2fa-status__header">
        <h1 class="cms-2fa-status__title">Two-Factor Authentication</h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    @if ($enrolled ?? false)
        <section class="cms-2fa-status__enrolled" aria-labelledby="2fa-enrolled-heading">
            <h2 class="cms-2fa-status__section-title" id="2fa-enrolled-heading">
                <span class="cms-2fa-status__icon cms-2fa-status__icon--enabled" aria-hidden="true">&#128737;</span>
                Two-factor authentication is enabled
            </h2>

            <dl class="cms-detail-list">
                <dt class="cms-detail-list__term">Recovery Codes Remaining</dt>
                <dd class="cms-detail-list__value">
                    @if (($recoveryCodesRemaining ?? 0) <= 2)
                        <strong class="cms-2fa-status__codes-warning">{{ $recoveryCodesRemaining ?? 0 }}</strong>
                        <span class="cms-form-group__hint">Consider regenerating your recovery codes.</span>
                    @else
                        {{ $recoveryCodesRemaining ?? 0 }}
                    @endif
                </dd>
            </dl>

            <form method="POST" action="/admin/cms/2fa/disable" class="cms-inline-form" data-cms-confirm="Disable two-factor authentication? This will reduce the security of your account." data-cms-confirm-reason>
                @csrf
                @method('DELETE')
                <button type="submit" class="cms-btn cms-btn--danger" data-cms-step-up>Disable 2FA</button>
            </form>
        </section>
    @else
        <section class="cms-2fa-status__not-enrolled" aria-labelledby="2fa-not-enrolled-heading">
            <h2 class="cms-2fa-status__section-title" id="2fa-not-enrolled-heading">
                <span class="cms-2fa-status__icon cms-2fa-status__icon--disabled" aria-hidden="true">&#128737;</span>
                Two-factor authentication is not enabled
            </h2>

            <p class="cms-2fa-status__description">
                Add an extra layer of security to your account. When enabled, you will need to enter a verification code from your authenticator app each time you sign in.
            </p>

            <a href="/admin/cms/2fa/enroll" class="cms-btn cms-btn--primary">Enable 2FA</a>
        </section>
    @endif
</div>

@include('cms::admin._partials.confirm-modal')
@include('cms::admin._partials.step-up-prompt')
@endsection
