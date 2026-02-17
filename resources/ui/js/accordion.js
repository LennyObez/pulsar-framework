/**
 * Pulsar UI — Accordion v1.0.0
 * Expand/collapse with smooth CSS animation.
 * Works with native `<details>`/`<summary>` and custom markup.
 * Zero dependencies.
 */
'use strict';

(function () {
  function initAccordion(accordion) {
    var triggers = Array.from(accordion.querySelectorAll('.pui-accordion__trigger'));

    triggers.forEach(function (trigger) {
      // Remove hidden from body elements — CSS handles visibility via grid-template-rows
      var contentId = trigger.getAttribute('aria-controls');
      var body = contentId ? document.getElementById(contentId) : null;
      if (body) {
        body.removeAttribute('hidden');
      }

      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        toggleItem(accordion, trigger);
      });

      trigger.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggleItem(accordion, trigger);
        }
      });
    });
  }

  function toggleItem(accordion, trigger) {
    var item = trigger.closest('.pui-accordion__item');
    if (!item) return;

    var isOpen = trigger.getAttribute('aria-expanded') === 'true';

    if (isOpen) {
      // Close
      trigger.setAttribute('aria-expanded', 'false');
      item.removeAttribute('open');
    } else {
      // Close others if single-expand mode
      if (accordion.dataset.singleExpand !== undefined) {
        accordion
          .querySelectorAll('.pui-accordion__trigger[aria-expanded="true"]')
          .forEach(function (otherTrigger) {
            if (otherTrigger !== trigger) {
              otherTrigger.setAttribute('aria-expanded', 'false');
              var otherItem = otherTrigger.closest('.pui-accordion__item');
              if (otherItem) {
                otherItem.removeAttribute('open');
              }
            }
          });
      }

      // Open
      trigger.setAttribute('aria-expanded', 'true');
      item.setAttribute('open', '');
    }
  }

  // Auto-initialize
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pui-accordion').forEach(initAccordion);
  });

  window.PulsarAccordion = { init: initAccordion };
})();
