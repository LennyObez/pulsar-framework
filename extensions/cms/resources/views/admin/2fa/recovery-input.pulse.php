@extends('admin.layout')

@section('title', 'Enter Recovery Code')

@section('content')
<div class="cms-2fa-recovery-input">
    <header class="cms-2fa-recovery-input__header">
        <h1 class="cms-2fa-recovery-input__title">Enter a Recovery Code</h1>
    </header>

    <p class="cms-2fa-recovery-input__description">
        If you cannot access your authenticator app, enter one of your recovery codes below to verify your identity.
    </p>

    <form method="POST" action="/admin/cms/2fa/recovery" class="cms-form">
        @csrf

        <div class="cms-form-group">
            <label for="recovery-code" class="cms-form-group__label">Recovery Code</label>
            <input type="text"
                   id="recovery-code"
                   name="recovery_code"
                   class="cms-form-group__input cms-2fa-recovery-input__code-field"
                   autocomplete="off"
                   spellcheck="false"
                   required
                   autofocus>
        </div>

        @if (isset($error))
            <div class="cms-alert cms-alert--danger" role="alert">
                <p>{{ $error }}</p>
            </div>
        @endif

        <div class="cms-2fa-recovery-input__actions">
            <button type="submit" class="cms-btn cms-btn--primary">Verify</button>
            <a href="/admin/cms/2fa" class="cms-btn cms-btn--link">Back to TOTP</a>
        </div>
    </form>
</div>
@endsection
