/**
 * Pulsar UI — Toast v1.0.0
 * Auto-dismiss with timer, close button.
 * Zero dependencies.
 */
'use strict';

(function () {
  const DEFAULT_DURATION = 5000;
  const CONTAINER_CLASS = 'pui-toast-container';
  const CONTAINER_POSITION = 'pui-toast-container--top-end';

  function getOrCreateContainer(position) {
    const positionClass = position || CONTAINER_POSITION;
    let container = document.querySelector('.' + CONTAINER_CLASS + '.' + positionClass);
    if (!container) {
      container = document.createElement('div');
      container.className = CONTAINER_CLASS + ' ' + positionClass;
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-atomic', 'false');
      document.body.appendChild(container);
    }
    return container;
  }

  function dismissToast(toast) {
    toast.classList.add('pui-toast--exiting');
    toast.addEventListener('animationend', function () {
      toast.remove();
    });
  }

  function show(options) {
    var title = options.title || '';
    var message = options.message || '';
    var variant = options.variant || 'info';
    var duration = options.duration !== undefined ? options.duration : DEFAULT_DURATION;
    var position = options.position || CONTAINER_POSITION;

    var container = getOrCreateContainer(position);

    var toast = document.createElement('div');
    toast.className = 'pui-toast pui-toast--' + variant;
    // Use role="alert" with aria-live="assertive" for danger/error toasts
    // so screen readers announce them immediately. Other variants use the
    // polite role="status" for non-urgent messages.
    if (variant === 'danger' || variant === 'error') {
      toast.setAttribute('role', 'alert');
      toast.setAttribute('aria-live', 'assertive');
    } else {
      toast.setAttribute('role', 'status');
    }
    toast.style.position = 'relative';

    var content = document.createElement('div');
    content.className = 'pui-toast__content';
    if (title) {
      var titleEl = document.createElement('div');
      titleEl.className = 'pui-toast__title';
      titleEl.textContent = title;
      content.appendChild(titleEl);
    }
    if (message) {
      var messageEl = document.createElement('div');
      messageEl.className = 'pui-toast__message';
      messageEl.textContent = message;
      content.appendChild(messageEl);
    }
    toast.appendChild(content);

    var closeBtn = document.createElement('button');
    closeBtn.className = 'pui-toast__close';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.setAttribute('data-toast-close', '');
    closeBtn.textContent = '\u00D7';
    toast.appendChild(closeBtn);

    container.appendChild(toast);

    // Auto-dismiss
    var timer = null;
    if (duration > 0) {
      timer = setTimeout(function () {
        dismissToast(toast);
      }, duration);
    }

    // Close button click handler (closeBtn already created above)
    closeBtn.addEventListener('click', function () {
      if (timer) clearTimeout(timer);
      dismissToast(toast);
    });

    return toast;
  }

  // Auto-bind close buttons
  document.addEventListener('click', function (e) {
    var closeBtn = e.target.closest('[data-toast-close]');
    if (closeBtn) {
      var toast = closeBtn.closest('.pui-toast');
      if (toast) {
        dismissToast(toast);
      }
    }
  });

  window.PulsarToast = { show: show, dismiss: dismissToast };
})();
