{{-- Confirmation modal partial. Activated via data-cms-confirm attribute on forms/buttons. --}}
<div class="cms-modal cms-modal--confirm" data-cms-modal role="dialog" aria-modal="true" aria-labelledby="cms-confirm-title" hidden>
    <div class="cms-modal__backdrop" data-cms-modal-close></div>
    <div class="cms-modal__dialog">
        <header class="cms-modal__header">
            <h2 class="cms-modal__title" id="cms-confirm-title" data-cms-modal-title>Confirm Action</h2>
            <button type="button" class="cms-modal__close" data-cms-modal-close aria-label="Close">&times;</button>
        </header>
        <div class="cms-modal__body">
            <p data-cms-modal-message>Are you sure you want to proceed?</p>
            <div class="cms-form-group" data-cms-modal-reason-group hidden>
                <label for="cms-confirm-reason" class="cms-form-group__label">Reason</label>
                <input type="text"
                       id="cms-confirm-reason"
                       class="cms-form-group__input"
                       data-cms-modal-reason
                       placeholder="Please provide a reason">
            </div>
        </div>
        <footer class="cms-modal__footer">
            <button type="button" class="cms-btn cms-btn--outline" data-cms-modal-close>Cancel</button>
            <button type="button" class="cms-btn cms-btn--danger" data-cms-modal-confirm>Confirm</button>
        </footer>
    </div>
</div>
