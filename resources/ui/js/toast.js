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
    toast.setAttribute('role', 'status');
    toast.style.position = 'relative';

    var html = '<div class="pui-toast__content">';
    if (title) {
      html += '<div class="pui-toast__title">' + escapeHtml(title) + '</div>';
    }
    if (message) {
      html += '<div class="pui-toast__message">' + escapeHtml(message) + '</div>';
    }
    html += '</div>';
    html +=
      '<button class="pui-toast__close" aria-label="Close notification" data-toast-close>&times;</button>';

    toast.innerHTML = html;
    container.appendChild(toast);

    // Auto-dismiss
    var timer = null;
    if (duration > 0) {
      timer = setTimeout(function () {
        dismissToast(toast);
      }, duration);
    }

    // Close button
    var closeBtn = toast.querySelector('[data-toast-close]');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        if (timer) clearTimeout(timer);
        dismissToast(toast);
      });
    }

    return toast;
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
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
