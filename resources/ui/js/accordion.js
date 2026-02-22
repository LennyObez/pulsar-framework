/**
 * Pulsar UI — Accordion v1.0.0
 * Expand/collapse with animation.
 * Works with native <details>/<summary> and custom markup.
 * Zero dependencies.
 */
'use strict';

(function () {
  function initAccordion(accordion) {
    var triggers = Array.from(accordion.querySelectorAll('.pui-accordion__trigger'));

    triggers.forEach(function (trigger) {
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
    var contentId = trigger.getAttribute('aria-controls');
    var content = contentId ? document.getElementById(contentId) : null;

    if (isOpen) {
      // Close
      trigger.setAttribute('aria-expanded', 'false');
      if (content) {
        content.hidden = true;
      }
      item.removeAttribute('open');
    } else {
      // Close others if single-expand mode
      if (accordion.dataset.singleExpand !== undefined) {
        accordion
          .querySelectorAll('.pui-accordion__trigger[aria-expanded="true"]')
          .forEach(function (otherTrigger) {
            if (otherTrigger !== trigger) {
              otherTrigger.setAttribute('aria-expanded', 'false');
              var otherId = otherTrigger.getAttribute('aria-controls');
              var otherContent = otherId ? document.getElementById(otherId) : null;
              if (otherContent) {
                otherContent.hidden = true;
              }
              var otherItem = otherTrigger.closest('.pui-accordion__item');
              if (otherItem) {
                otherItem.removeAttribute('open');
              }
            }
          });
      }

      // Open
      trigger.setAttribute('aria-expanded', 'true');
      if (content) {
        content.hidden = false;
      }
      item.setAttribute('open', '');
    }
  }

  // Auto-initialize
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pui-accordion').forEach(initAccordion);
  });

  window.PulsarAccordion = { init: initAccordion };
})();
