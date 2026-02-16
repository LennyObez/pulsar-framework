{{-- Destructive action confirmation modal.
     Expects:
       $actionDescription (string): e.g. "delete this plugin"
       $formAction (string): form POST target URL
       $formMethod (string, optional): HTTP method override (default: DELETE)
       $csrfToken (string): CSRF token value
--}}
<div class="cms-modal cms-modal--destructive" data-cms-destructive-modal role="dialog" aria-modal="true" aria-labelledby="cms-destructive-title" hidden>
    <div class="cms-modal__backdrop" data-cms-modal-close></div>
    <div class="cms-modal__dialog">
        <header class="cms-modal__header">
            <h2 class="cms-modal__title" id="cms-destructive-title">Confirm Destructive Action</h2>
            <button type="button" class="cms-modal__close" data-cms-modal-close aria-label="Close">&times;</button>
        </header>
        <form method="POST" action="{{ $formAction ?? '' }}" data-cms-destructive-form>
            <input type="hidden" name="_token" value="{{ $csrfToken ?? '' }}">
            <input type="hidden" name="_method" value="{{ $formMethod ?? 'DELETE' }}">

            <div class="cms-modal__body">
                <div class="cms-modal__warning-icon" aria-hidden="true">&#9888;</div>
                <p class="cms-modal__action-description">
                    You are about to <strong>{{ $actionDescription ?? 'perform a destructive action' }}</strong>.
                </p>
                <p class="cms-modal__irreversible">This action cannot be undone.</p>

                <div class="cms-form-group">
                    <label for="cms-destructive-reason" class="cms-form-group__label">Reason for this action (required)</label>
                    <textarea id="cms-destructive-reason"
                              name="reason"
                              class="cms-form-group__textarea"
                              rows="3"
                              minlength="10"
                              required
                              data-cms-destructive-reason></textarea>
                    <span class="cms-form-group__hint">
                        <span data-cms-destructive-char-count>0</span>/10 characters minimum
                    </span>
                </div>
            </div>

            <footer class="cms-modal__footer">
                <button type="button" class="cms-btn cms-btn--outline" data-cms-modal-close>Cancel</button>
                <button type="submit"
                        class="cms-btn cms-btn--danger"
                        disabled
                        data-cms-destructive-confirm
                        data-confirm-delay="3000">
                    Confirm (3s)
                </button>
            </footer>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.querySelector('[data-cms-destructive-modal]');
    if (!modal) return;

    var reason = modal.querySelector('[data-cms-destructive-reason]');
    var charCount = modal.querySelector('[data-cms-destructive-char-count]');
    var confirmBtn = modal.querySelector('[data-cms-destructive-confirm]');

    if (!reason || !charCount || !confirmBtn) return;

    var delay = parseInt(confirmBtn.getAttribute('data-confirm-delay') || '3000', 10);
    var countdownInterval = null;
    var canSubmit = false;

    function updateCharCount() {
        var len = (reason.value || '').length;
        charCount.textContent = String(len);
    }

    function startCountdown() {
        canSubmit = false;
        confirmBtn.disabled = true;
        var remaining = Math.ceil(delay / 1000);
        confirmBtn.textContent = 'Confirm (' + remaining + 's)';

        countdownInterval = setInterval(function () {
            remaining--;
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                canSubmit = true;
                confirmBtn.textContent = 'Confirm';
                if (reason.value.length >= 10) {
                    confirmBtn.disabled = false;
                }
            } else {
                confirmBtn.textContent = 'Confirm (' + remaining + 's)';
            }
        }, 1000);
    }

    reason.addEventListener('input', function () {
        updateCharCount();
        if (canSubmit && reason.value.length >= 10) {
            confirmBtn.disabled = false;
        } else {
            confirmBtn.disabled = true;
        }
    });

    // Start countdown when modal becomes visible
    var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].attributeName === 'hidden') {
                if (!modal.hidden) {
                    reason.value = '';
                    updateCharCount();
                    startCountdown();
                } else if (countdownInterval) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
            }
        }
    });
    observer.observe(modal, { attributes: true });

    // Close handlers
    var closeBtns = modal.querySelectorAll('[data-cms-modal-close]');
    for (var i = 0; i < closeBtns.length; i++) {
        closeBtns[i].addEventListener('click', function () {
            modal.hidden = true;
        });
    }
})();
</script>
