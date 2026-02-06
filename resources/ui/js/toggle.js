/**
 * Pulsar UI — Toggle v1.0.0
 * Toggle switch behavior for role="switch" elements.
 * Zero dependencies.
 */
'use strict';

(function () {
  document.addEventListener('change', function (e) {
    var input = e.target;
    if (input.getAttribute('role') !== 'switch' && !input.closest('.pui-toggle')) return;

    input.setAttribute('aria-checked', String(input.checked));
  });

  // Initialize aria-checked on page load
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pui-toggle input[type="checkbox"]').forEach(function (input) {
      input.setAttribute('aria-checked', String(input.checked));
    });
  });
})();
