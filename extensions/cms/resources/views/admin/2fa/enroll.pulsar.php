@extends('admin.layout')

@section('title', 'Enable 2FA')

@section('content')
<div class="cms-2fa-enroll">
    <header class="cms-2fa-enroll__header">
        <h1 class="cms-2fa-enroll__title">Enable Two-Factor Authentication</h1>
        <a href="/admin/cms/2fa" class="cms-btn cms-btn--outline">Cancel</a>
    </header>

    <section class="cms-2fa-enroll__setup" aria-labelledby="setup-heading">
        <h2 class="cms-2fa-enroll__section-title" id="setup-heading">Scan QR Code</h2>

        <p class="cms-2fa-enroll__instructions">
            Scan the QR code below with your authenticator app (such as Google Authenticator, Authy, or 1Password), then enter the verification code to complete setup.
        </p>

        {{-- QR code display --}}
        <div class="cms-2fa-enroll__qr" aria-label="QR code for authenticator app setup">
            {!! $qrCodeSvg ?? '' !!}
        </div>

        {{-- Manual entry fallback --}}
        <div class="cms-2fa-enroll__manual">
            <button type="button"
                    class="cms-btn cms-btn--link"
                    data-cms-toggle="cms-2fa-manual-setup"
                    aria-expanded="false"
                    aria-controls="cms-2fa-manual-setup">
                Can't scan? Show manual setup
            </button>
            <div id="cms-2fa-manual-setup" class="cms-2fa-enroll__manual-content" hidden>
                <p class="cms-form-group__hint">Enter this key into your authenticator app manually:</p>
                <div class="cms-2fa-enroll__secret-display">
                    <code class="cms-2fa-enroll__secret" id="cms-2fa-secret">{{ $otpauthUri ?? '' }}</code>
                    <button type="button"
                            class="cms-btn cms-btn--sm cms-btn--outline"
                            data-cms-copy="cms-2fa-secret"
                            aria-label="Copy setup key to clipboard">
                        Copy
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- Verification form --}}
    <section class="cms-2fa-enroll__verify" aria-labelledby="verify-heading">
        <h2 class="cms-2fa-enroll__section-title" id="verify-heading">Verify Code</h2>

        <form method="POST" action="/admin/cms/2fa/enroll" class="cms-form">
            @csrf

            <div class="cms-form-group">
                <label for="totp-code" class="cms-form-group__label">Verification Code</label>
                <input type="text"
                       id="totp-code"
                       name="code"
                       class="cms-form-group__input cms-2fa-enroll__code-input"
                       inputmode="numeric"
                       pattern="[0-9]{6}"
                       maxlength="6"
                       autocomplete="one-time-code"
                       placeholder="000000"
                       required
                       autofocus>
                <p class="cms-form-group__hint">Enter the 6-digit code from your authenticator app.</p>
            </div>

            @if (isset($error))
                <div class="cms-alert cms-alert--danger" role="alert">
                    <p>{{ $error }}</p>
                </div>
            @endif

            <button type="submit" class="cms-btn cms-btn--primary">Verify and Enable</button>
        </form>
    </section>
</div>

<script>
(function () {
    // Toggle manual setup visibility
    var toggleBtn = document.querySelector('[data-cms-toggle="cms-2fa-manual-setup"]');
    var manual = document.getElementById('cms-2fa-manual-setup');
    if (toggleBtn && manual) {
        toggleBtn.addEventListener('click', function () {
            var expanded = toggleBtn.getAttribute('aria-expanded') === 'true';
            toggleBtn.setAttribute('aria-expanded', String(!expanded));
            manual.hidden = expanded;
        });
    }

    // Copy to clipboard
    var copyBtns = document.querySelectorAll('[data-cms-copy]');
    for (var i = 0; i < copyBtns.length; i++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-cms-copy');
                var target = document.getElementById(targetId);
                if (target && navigator.clipboard) {
                    navigator.clipboard.writeText(target.textContent.trim()).then(function () {
                        var original = btn.textContent;
                        btn.textContent = 'Copied';
                        setTimeout(function () { btn.textContent = original; }, 2000);
                    });
                }
            });
        })(copyBtns[i]);
    }
})();
</script>
@endsection
