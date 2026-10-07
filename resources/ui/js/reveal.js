/**
 * Progressive-enhancement reveal-on-scroll — script-src 'self' clean.
 *
 * Adds `.is-revealed` to `[data-pulsar-reveal]` elements as they enter the
 * viewport, letting progressive-enhancement.css animate them in. This file is
 * served same-origin (no inline code), so it needs no CSP hash or nonce.
 *
 * Nothing depends on this script running: the hidden pre-animation state is set
 * by CSS via `@media (scripting: enabled)`, and that CSS carries a fail-safe
 * animation, so if this bundle never loads the content still reveals itself.
 * There is no `js` / `no-js` class and no inline bootstrap anywhere.
 */
(function () {
  'use strict';

  function revealAll(elements) {
    for (var i = 0; i < elements.length; i++) {
      elements[i].classList.add('is-revealed');
    }
  }

  function init() {
    var elements = document.querySelectorAll('[data-pulsar-reveal]');
    if (elements.length === 0) {
      return;
    }

    // Old engine without IntersectionObserver: reveal immediately. There the
    // `scripting` media feature is unsupported too, so the elements are already
    // visible — adding the class is a harmless no-op that keeps state coherent.
    if (!('IntersectionObserver' in window)) {
      revealAll(elements);
      return;
    }

    var observer = new IntersectionObserver(
      function (entries, obs) {
        for (var i = 0; i < entries.length; i++) {
          if (entries[i].isIntersecting) {
            entries[i].target.classList.add('is-revealed');
            obs.unobserve(entries[i].target);
          }
        }
      },
      { rootMargin: '0px 0px -10% 0px', threshold: 0.05 },
    );

    for (var j = 0; j < elements.length; j++) {
      observer.observe(elements[j]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
