/**
 * Pulsar UI — Modal v1.0.0
 * Open/close with focus trap, Escape key, backdrop click.
 * Zero dependencies.
 */
'use strict';

(function () {
  const FOCUSABLE =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

  var modalIdCounter = 0;

  function openModal(backdrop) {
    backdrop.hidden = false;
    backdrop.removeAttribute('aria-hidden');
    document.body.style.overflow = 'hidden';

    const modal = backdrop.querySelector('.pui-modal');
    if (modal) {
      // Set ARIA dialog attributes for screen readers
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');

      // Point aria-labelledby to the modal's heading if one exists
      var heading = modal.querySelector(
        '.pui-modal__title, h1, h2, h3, h4, h5, h6, [data-modal-title]',
      );
      if (heading) {
        if (!heading.id) {
          heading.id = 'pui-modal-title-' + ++modalIdCounter;
        }
        modal.setAttribute('aria-labelledby', heading.id);
      }

      const firstFocusable = modal.querySelector(FOCUSABLE);
      if (firstFocusable) {
        firstFocusable.focus();
      }
    }

    backdrop.addEventListener('keydown', handleKeydown);
    backdrop.addEventListener('click', handleBackdropClick);
  }

  function closeModal(backdrop) {
    backdrop.classList.add('pui-modal-backdrop--exiting');

    function onEnd() {
      backdrop.classList.remove('pui-modal-backdrop--exiting');
      backdrop.hidden = true;
      backdrop.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      backdrop.removeEventListener('keydown', handleKeydown);
      backdrop.removeEventListener('click', handleBackdropClick);
      backdrop.removeEventListener('animationend', onEnd);

      // Return focus to the trigger element
      const triggerId = backdrop.dataset.triggerId;
      if (triggerId) {
        const trigger = document.getElementById(triggerId);
        if (trigger) {
          trigger.focus();
        }
      }
    }

    backdrop.addEventListener('animationend', onEnd);
  }

  function handleKeydown(e) {
    const backdrop = e.currentTarget;
    const modal = backdrop.querySelector('.pui-modal');

    if (e.key === 'Escape') {
      e.preventDefault();
      closeModal(backdrop);
      return;
    }

    // Focus trap
    if (e.key === 'Tab' && modal) {
      const focusableEls = modal.querySelectorAll(FOCUSABLE);
      if (focusableEls.length === 0) return;

      const firstEl = focusableEls[0];
      const lastEl = focusableEls[focusableEls.length - 1];

      if (e.shiftKey) {
        if (document.activeElement === firstEl) {
          e.preventDefault();
          lastEl.focus();
        }
      } else {
        if (document.activeElement === lastEl) {
          e.preventDefault();
          firstEl.focus();
        }
      }
    }
  }

  function handleBackdropClick(e) {
    if (e.target === e.currentTarget) {
      closeModal(e.currentTarget);
    }
  }

  // Auto-bind triggers with data attributes
  document.addEventListener('click', function (e) {
    const trigger = e.target.closest('[data-modal-open]');
    if (trigger) {
      e.preventDefault();
      const backdropId = trigger.getAttribute('data-modal-open');
      const backdrop = document.getElementById(backdropId);
      if (backdrop) {
        backdrop.dataset.triggerId = trigger.id || '';
        openModal(backdrop);
      }
    }

    const closeBtn = e.target.closest('[data-modal-close]');
    if (closeBtn) {
      e.preventDefault();
      const backdrop = closeBtn.closest('.pui-modal-backdrop');
      if (backdrop) {
        closeModal(backdrop);
      }
    }
  });

  // Expose API
  window.PulsarModal = { open: openModal, close: closeModal };
})();
