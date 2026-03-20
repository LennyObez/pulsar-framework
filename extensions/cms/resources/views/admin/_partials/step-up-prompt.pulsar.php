{{-- Step-up authentication modal.
     Triggered by elements with data-cms-step-up attribute.
     The form that triggered step-up is submitted after successful verification.
--}}
<div class="cms-modal cms-modal--step-up" data-cms-step-up-modal role="dialog" aria-modal="true" aria-labelledby="cms-step-up-title" hidden>
    <div class="cms-modal__backdrop" data-cms-modal-close></div>
    <div class="cms-modal__dialog">
        <header class="cms-modal__header">
            <h2 class="cms-modal__title" id="cms-step-up-title">Verify Your Identity</h2>
            <button type="button" class="cms-modal__close" data-cms-modal-close aria-label="Close">&times;</button>
        </header>
        <div class="cms-modal__body">
            <p class="cms-modal__description">This action requires additional verification.</p>

            {{-- TOTP input --}}
            <div data-cms-step-up-totp>
                <div class="cms-form-group">
                    <label for="cms-step-up-code" class="cms-form-group__label">Authentication Code</label>
                    <input type="text"
                           id="cms-step-up-code"
                           class="cms-form-group__input cms-step-up__code-input"
                           inputmode="numeric"
                           pattern="[0-9]{6}"
                           maxlength="6"
                           autocomplete="one-time-code"
                           placeholder="000000"
                           data-cms-step-up-code>
                    <p class="cms-form-group__hint">Enter the 6-digit code from your authenticator app.</p>
                </div>

                <div class="cms-step-up__toggle">
                    <button type="button"
                            class="cms-btn cms-btn--link"
                            data-cms-step-up-toggle="recovery"
                            aria-expanded="false">
                        Use recovery code
                    </button>
                </div>
            </div>

            {{-- Recovery code input (hidden by default) --}}
            <div data-cms-step-up-recovery hidden>
                <div class="cms-form-group">
                    <label for="cms-step-up-recovery-code" class="cms-form-group__label">Recovery Code</label>
                    <input type="text"
                           id="cms-step-up-recovery-code"
                           class="cms-form-group__input cms-step-up__recovery-input"
                           autocomplete="off"
                           spellcheck="false"
                           data-cms-step-up-recovery-code>
                </div>

                <div class="cms-step-up__toggle">
                    <button type="button"
                            class="cms-btn cms-btn--link"
                            data-cms-step-up-toggle="totp"
                            aria-expanded="false">
                        Use authenticator code
                    </button>
                </div>
            </div>

            <div class="cms-alert cms-alert--danger" role="alert" data-cms-step-up-error hidden>
                <p data-cms-step-up-error-message></p>
            </div>
        </div>
        <footer class="cms-modal__footer">
            <button type="button" class="cms-btn cms-btn--outline" data-cms-modal-close>Cancel</button>
            <button type="button" class="cms-btn cms-btn--primary" data-cms-step-up-verify>Verify</button>
        </footer>
    </div>
</div>

<script>
(function () {
    var modal = document.querySelector('[data-cms-step-up-modal]');
    if (!modal) return;

    var totpSection = modal.querySelector('[data-cms-step-up-totp]');
    var recoverySection = modal.querySelector('[data-cms-step-up-recovery]');
    var codeInput = modal.querySelector('[data-cms-step-up-code]');
    var recoveryInput = modal.querySelector('[data-cms-step-up-recovery-code]');
    var errorEl = modal.querySelector('[data-cms-step-up-error]');
    var verifyBtn = modal.querySelector('[data-cms-step-up-verify]');

    var pendingForm = null;

    // Toggle between TOTP and recovery
    var toggleBtns = modal.querySelectorAll('[data-cms-step-up-toggle]');
    for (var i = 0; i < toggleBtns.length; i++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-cms-step-up-toggle');
                if (target === 'recovery') {
                    totpSection.hidden = true;
                    recoverySection.hidden = false;
                    recoveryInput.focus();
                } else {
                    totpSection.hidden = false;
                    recoverySection.hidden = true;
                    codeInput.focus();
                }
                errorEl.hidden = true;
            });
        })(toggleBtns[i]);
    }

    // Close handlers
    var closeBtns = modal.querySelectorAll('[data-cms-modal-close]');
    for (var i = 0; i < closeBtns.length; i++) {
        closeBtns[i].addEventListener('click', function () {
            modal.hidden = true;
            pendingForm = null;
        });
    }

    // Intercept step-up triggers
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-cms-step-up]');
        if (!trigger) return;

        var form = trigger.closest('form');
        if (form) {
            e.preventDefault();
            pendingForm = form;
            modal.hidden = false;

            // Reset state
            totpSection.hidden = false;
            recoverySection.hidden = true;
            codeInput.value = '';
            recoveryInput.value = '';
            errorEl.hidden = true;
            codeInput.focus();
        }
    });

    // Verify and submit
    if (verifyBtn) {
        verifyBtn.addEventListener('click', function () {
            var code = '';
            var fieldName = '';
            if (!totpSection.hidden) {
                code = codeInput.value.trim();
                fieldName = 'step_up_code';
            } else {
                code = recoveryInput.value.trim();
                fieldName = 'step_up_recovery_code';
            }

            if (!code) {
                var msg = modal.querySelector('[data-cms-step-up-error-message]');
                if (msg) msg.textContent = 'Please enter a verification code.';
                errorEl.hidden = false;
                return;
            }

            if (pendingForm) {
                // Inject step-up code into the pending form
                var existing = pendingForm.querySelector('input[name="' + fieldName + '"]');
                if (existing) {
                    existing.value = code;
                } else {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = fieldName;
                    input.value = code;
                    pendingForm.appendChild(input);
                }
                modal.hidden = true;
                pendingForm.submit();
                pendingForm = null;
            }
        });
    }
})();
</script>
