/**
 * Pulsar UI — Image theft deterrent utilities.
 *
 * These are deterrents, not absolute protection. Determined users can
 * always capture screen content. Document this clearly in your project.
 *
 * Usage:
 *   <img data-pui-protected>              — enables right-click + drag prevention
 *   <div data-pui-protected-gallery>      — protects all images inside
 *
 * Configuration via data attributes:
 *   data-pui-no-context-menu="false"      — disable right-click prevention
 *   data-pui-no-drag="false"              — disable drag prevention
 *   data-pui-overlay="true"               — add transparent overlay div
 */
(function () {
  'use strict';

  const SELECTOR_PROTECTED = '[data-pui-protected]';
  const SELECTOR_GALLERY = '[data-pui-protected-gallery]';

  /**
   * Prevent the browser context menu on an element.
   * @param {HTMLElement} el
   */
  function preventContextMenu(el) {
    if (el.dataset.puiNoContextMenu === 'false') {
      return;
    }

    el.addEventListener(
      'contextmenu',
      function (e) {
        e.preventDefault();
      },
      { passive: false },
    );
  }

  /**
   * Prevent drag on an element.
   * @param {HTMLElement} el
   */
  function preventDrag(el) {
    if (el.dataset.puiNoDrag === 'false') {
      return;
    }

    el.setAttribute('draggable', 'false');

    el.addEventListener(
      'dragstart',
      function (e) {
        e.preventDefault();
      },
      { passive: false },
    );

    el.style.userSelect = 'none';
    el.style.webkitUserSelect = 'none';
  }

  /**
   * Add a transparent overlay div over the element to prevent simple drag-save.
   * @param {HTMLElement} el
   */
  function addOverlay(el) {
    if (el.dataset.puiOverlay !== 'true') {
      return;
    }

    var parent = el.parentElement;

    if (!parent) {
      return;
    }

    // Ensure parent is positioned
    var parentPosition = window.getComputedStyle(parent).position;

    if (parentPosition === 'static') {
      parent.style.position = 'relative';
    }

    var overlay = document.createElement('div');
    overlay.className = 'pui-image-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.style.position = 'absolute';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.zIndex = '1';
    overlay.style.background = 'transparent';

    parent.appendChild(overlay);
  }

  /**
   * Apply protection to a single element.
   * @param {HTMLElement} el
   */
  function protect(el) {
    if (el.dataset.puiProtectionApplied) {
      return;
    }

    preventContextMenu(el);
    preventDrag(el);
    addOverlay(el);

    el.dataset.puiProtectionApplied = 'true';
  }

  /**
   * Apply protection to all images inside a gallery container.
   * @param {HTMLElement} gallery
   */
  function protectGallery(gallery) {
    var images = gallery.querySelectorAll('img');

    for (var i = 0; i < images.length; i++) {
      protect(images[i]);
    }
  }

  /**
   * Initialize protection on all matching elements in the DOM.
   */
  function init() {
    var protectedElements = document.querySelectorAll(SELECTOR_PROTECTED);

    for (var i = 0; i < protectedElements.length; i++) {
      protect(protectedElements[i]);
    }

    var galleries = document.querySelectorAll(SELECTOR_GALLERY);

    for (var j = 0; j < galleries.length; j++) {
      protectGallery(galleries[j]);
    }
  }

  // Auto-initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Observe for dynamically added elements
  if (typeof MutationObserver !== 'undefined') {
    var observer = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var addedNodes = mutations[i].addedNodes;

        for (var j = 0; j < addedNodes.length; j++) {
          var node = addedNodes[j];

          if (node.nodeType !== Node.ELEMENT_NODE) {
            continue;
          }

          /** @type {HTMLElement} */
          var el = /** @type {HTMLElement} */ (node);

          if (el.matches && el.matches(SELECTOR_PROTECTED)) {
            protect(el);
          }

          if (el.matches && el.matches(SELECTOR_GALLERY)) {
            protectGallery(el);
          }

          // Check children
          if (el.querySelectorAll) {
            var innerProtected = el.querySelectorAll(SELECTOR_PROTECTED);

            for (var k = 0; k < innerProtected.length; k++) {
              protect(innerProtected[k]);
            }

            var innerGalleries = el.querySelectorAll(SELECTOR_GALLERY);

            for (var m = 0; m < innerGalleries.length; m++) {
              protectGallery(innerGalleries[m]);
            }
          }
        }
      }
    });

    observer.observe(document.body || document.documentElement, {
      childList: true,
      subtree: true,
    });
  }

  // Export for programmatic use
  if (typeof window !== 'undefined') {
    window.PulsarImageProtection = {
      protect: protect,
      protectGallery: protectGallery,
      init: init,
    };
  }
})();
