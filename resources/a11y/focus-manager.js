/**
 * Pulsar Focus Manager v1.0.0
 *
 * Keyboard navigation utilities: focus trapping, focus restoration,
 * roving tabindex, skip navigation, and Escape key handling.
 *
 * Zero dependencies. ES module. Auto-initializes on DOMContentLoaded.
 *
 * @example
 * import { trapFocus, releaseFocus, saveFocus, restoreFocus } from './focus-manager.js';
 *
 * // Trap focus in a modal
 * trapFocus(modalElement);
 *
 * // Later, release and restore
 * releaseFocus(modalElement);
 * restoreFocus();
 */

/** @type {string} Selector for all natively focusable elements */
const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
  'details > summary',
  '[contenteditable]:not([contenteditable="false"])',
].join(', ');

/**
 * Stack of previously focused elements for save/restore.
 * @type {Element[]}
 */
const focusStack = [];

/**
 * Map of active focus traps keyed by container element.
 * Stores the keydown handler reference for cleanup.
 * @type {Map<Element, Function>}
 */
const activeFocusTraps = new Map();

/**
 * Map of active roving tabindex groups keyed by container element.
 * @type {Map<Element, Function>}
 */
const activeRovingGroups = new Map();

/**
 * MutationObserver for watching focus trap containers for DOM changes.
 * @type {MutationObserver|null}
 */
let trapObserver = null;

/* ===========================================================================
 * Focus Querying
 * =========================================================================== */

/**
 * Returns all focusable elements within a container, in DOM order.
 * Filters out elements that are visually hidden or have zero dimensions.
 *
 * @param {Element} container - The container to search within.
 * @returns {Element[]} Ordered list of focusable elements.
 */
function getFocusableElements(container) {
  const elements = Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR));

  return elements.filter((el) => {
    // Skip elements hidden via CSS or the hidden attribute
    if (el.hidden || el.closest('[hidden]')) return false;

    const style = getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;

    return true;
  });
}

/* ===========================================================================
 * Focus Trap
 * =========================================================================== */

/**
 * Activates a focus trap on the given container. Tab and Shift+Tab cycle
 * through focusable descendants. Escape dismisses the trap.
 *
 * @param {Element} container - The element to trap focus within.
 * @param {Object} [options] - Configuration options.
 * @param {boolean} [options.autoFocus=true] - Focus the first focusable element immediately.
 * @param {boolean} [options.escapeDeactivates=true] - Whether Escape releases the trap.
 */
function trapFocus(container, options = {}) {
  const { autoFocus = true, escapeDeactivates = true } = options;

  // If already trapping this container, do nothing
  if (activeFocusTraps.has(container)) return;

  // Save current focus before trapping
  saveFocus();

  container.setAttribute('data-focus-trap', '');

  /** @param {KeyboardEvent} event */
  function handleKeydown(event) {
    if (event.key === 'Escape' && escapeDeactivates) {
      event.preventDefault();
      event.stopPropagation();
      releaseFocus(container);
      restoreFocus();
      container.dispatchEvent(new CustomEvent('pui:focus-trap:escape', { bubbles: true }));
      return;
    }

    if (event.key !== 'Tab') return;

    const focusable = getFocusableElements(container);
    if (focusable.length === 0) {
      event.preventDefault();
      return;
    }

    const firstEl = focusable[0];
    const lastEl = focusable[focusable.length - 1];

    if (event.shiftKey) {
      if (document.activeElement === firstEl || !container.contains(document.activeElement)) {
        event.preventDefault();
        lastEl.focus();
      }
    } else {
      if (document.activeElement === lastEl || !container.contains(document.activeElement)) {
        event.preventDefault();
        firstEl.focus();
      }
    }
  }

  container.addEventListener('keydown', handleKeydown);
  activeFocusTraps.set(container, handleKeydown);

  // Auto-focus the first focusable element
  if (autoFocus) {
    const focusable = getFocusableElements(container);
    if (focusable.length > 0) {
      focusable[0].focus();
    } else {
      // Make the container itself focusable as a fallback
      if (!container.hasAttribute('tabindex')) {
        container.setAttribute('tabindex', '-1');
        container.dataset.focusTrapTabindex = '';
      }
      container.focus();
    }
  }
}

/**
 * Releases a focus trap, removing the keydown handler and data attribute.
 *
 * @param {Element} container - The container to release.
 */
function releaseFocus(container) {
  const handler = activeFocusTraps.get(container);
  if (!handler) return;

  container.removeEventListener('keydown', handler);
  activeFocusTraps.delete(container);
  container.removeAttribute('data-focus-trap');

  // Clean up tabindex if we added it
  if (container.dataset.focusTrapTabindex !== undefined) {
    container.removeAttribute('tabindex');
    delete container.dataset.focusTrapTabindex;
  }
}

/* ===========================================================================
 * Focus Save / Restore Stack
 * =========================================================================== */

/**
 * Saves the currently focused element onto the stack.
 * Use before opening modals or other focus-stealing UI.
 */
function saveFocus() {
  if (document.activeElement && document.activeElement !== document.body) {
    focusStack.push(document.activeElement);
  }
}

/**
 * Pops the most recently saved element from the stack and focuses it.
 * Silently no-ops if the stack is empty or the element is gone.
 */
function restoreFocus() {
  const prev = focusStack.pop();
  if (!prev) return;

  // Verify the element is still in the DOM and focusable
  if (prev.isConnected) {
    prev.focus();
  }
}

/**
 * Returns the current depth of the focus stack.
 * Useful for debugging nested modals.
 *
 * @returns {number} Number of saved focus positions.
 */
function getFocusStackDepth() {
  return focusStack.length;
}

/* ===========================================================================
 * Roving Tabindex
 * =========================================================================== */

/**
 * Initializes roving tabindex on a container. Children matching the
 * `itemSelector` are navigable with arrow keys. Only the active item
 * has tabindex="0"; all others have tabindex="-1".
 *
 * @param {Element} container - The group container (e.g., a toolbar).
 * @param {Object} [options] - Configuration.
 * @param {string} [options.itemSelector='[role="tab"], [role="menuitem"], [role="option"], button'] - Selector for navigable items.
 * @param {boolean} [options.wrap=true] - Wrap around at the ends.
 * @param {boolean} [options.vertical=false] - Use Up/Down instead of Left/Right.
 */
function initRovingTabindex(container, options = {}) {
  const {
    itemSelector = '[role="tab"], [role="menuitem"], [role="option"], button',
    wrap = true,
    vertical = false,
  } = options;

  if (activeRovingGroups.has(container)) return;

  container.setAttribute('data-roving-tab', '');

  const items = () =>
    Array.from(container.querySelectorAll(itemSelector)).filter((el) => !el.disabled && !el.hidden);

  // Set initial tabindex state
  const allItems = items();
  const activeItem = allItems.find((el) => el.getAttribute('tabindex') === '0') || allItems[0];

  allItems.forEach((el) => {
    el.setAttribute('tabindex', el === activeItem ? '0' : '-1');
  });

  const nextKey = vertical ? 'ArrowDown' : 'ArrowRight';
  const prevKey = vertical ? 'ArrowUp' : 'ArrowLeft';

  /** @param {KeyboardEvent} event */
  function handleKeydown(event) {
    const currentItems = items();
    if (currentItems.length === 0) return;

    const current = currentItems.indexOf(document.activeElement);
    if (current === -1) return;

    let next = -1;

    if (event.key === nextKey) {
      event.preventDefault();
      next = current + 1;
      if (next >= currentItems.length) {
        next = wrap ? 0 : currentItems.length - 1;
      }
    } else if (event.key === prevKey) {
      event.preventDefault();
      next = current - 1;
      if (next < 0) {
        next = wrap ? currentItems.length - 1 : 0;
      }
    } else if (event.key === 'Home') {
      event.preventDefault();
      next = 0;
    } else if (event.key === 'End') {
      event.preventDefault();
      next = currentItems.length - 1;
    }

    if (next !== -1 && next !== current) {
      currentItems[current].setAttribute('tabindex', '-1');
      currentItems[next].setAttribute('tabindex', '0');
      currentItems[next].focus();
    }
  }

  container.addEventListener('keydown', handleKeydown);
  activeRovingGroups.set(container, handleKeydown);
}

/**
 * Removes roving tabindex behavior from a container.
 *
 * @param {Element} container - The container to clean up.
 */
function destroyRovingTabindex(container) {
  const handler = activeRovingGroups.get(container);
  if (!handler) return;

  container.removeEventListener('keydown', handler);
  activeRovingGroups.delete(container);
  container.removeAttribute('data-roving-tab');
}

/* ===========================================================================
 * Skip Links
 * =========================================================================== */

/**
 * Initializes skip link handling. When a `.pui-skip-link` is clicked or
 * activated, focus moves to the element referenced by its `href` fragment.
 */
function initSkipLinks() {
  document.addEventListener(
    'click',
    (event) => {
      const link = event.target.closest('.pui-skip-link');
      if (!link) return;

      const href = link.getAttribute('href');
      if (!href || !href.startsWith('#')) return;

      event.preventDefault();

      const targetId = href.slice(1);
      const target = document.getElementById(targetId);
      if (!target) return;

      // Make the target focusable if it is not already
      if (!target.hasAttribute('tabindex') && !target.matches(FOCUSABLE_SELECTOR)) {
        target.setAttribute('tabindex', '-1');
        target.addEventListener(
          'blur',
          () => {
            target.removeAttribute('tabindex');
          },
          { once: true },
        );
      }

      target.focus();
      target.scrollIntoView({ behavior: 'auto', block: 'start' });
    },
    { passive: false },
  );
}

/* ===========================================================================
 * Escape Key Global Handler
 * =========================================================================== */

/**
 * Initializes global Escape key handling. When Escape is pressed, the
 * innermost active focus trap is dismissed. If no trap is active, the
 * event is ignored.
 */
function initEscapeHandler() {
  document.addEventListener(
    'keydown',
    (event) => {
      if (event.key !== 'Escape') return;

      // Find the innermost focus trap that contains the active element
      const traps = Array.from(activeFocusTraps.keys());
      if (traps.length === 0) return;

      // Sort by DOM depth — deepest first
      const sorted = traps
        .filter((trap) => trap.isConnected)
        .sort((a, b) => {
          if (a.contains(b)) return 1;
          if (b.contains(a)) return -1;
          return 0;
        });

      const innermost = sorted[sorted.length - 1];
      if (innermost && innermost.contains(document.activeElement)) {
        // The trap's own handler will deal with this
        return;
      }
    },
    { passive: true },
  );
}

/* ===========================================================================
 * Auto-initialization from data attributes
 * =========================================================================== */

/**
 * Scans the DOM for elements with focus management data attributes and
 * initializes them. Called on DOMContentLoaded and can be called manually
 * after dynamic content insertion.
 */
function initFromDataAttributes() {
  // Initialize focus traps marked in HTML
  document.querySelectorAll('[data-focus-trap]').forEach((container) => {
    if (!activeFocusTraps.has(container)) {
      trapFocus(container, { autoFocus: false, escapeDeactivates: true });
    }
  });

  // Initialize roving tabindex groups
  document.querySelectorAll('[data-roving-tab]').forEach((container) => {
    if (!activeRovingGroups.has(container)) {
      const vertical = container.hasAttribute('data-roving-vertical');
      const selector = container.dataset.rovingSelector || undefined;
      initRovingTabindex(container, { vertical, itemSelector: selector });
    }
  });
}

/**
 * Starts a MutationObserver that automatically initializes focus traps and
 * roving groups when they are added to the DOM dynamically.
 */
function startObserver() {
  if (trapObserver) return;

  trapObserver = new MutationObserver((mutations) => {
    let needsScan = false;

    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (node.nodeType !== Node.ELEMENT_NODE) continue;

        if (node.hasAttribute('data-focus-trap') || node.hasAttribute('data-roving-tab')) {
          needsScan = true;
          break;
        }

        if (node.querySelector('[data-focus-trap], [data-roving-tab]')) {
          needsScan = true;
          break;
        }
      }

      if (needsScan) break;
    }

    if (needsScan) {
      initFromDataAttributes();
    }
  });

  trapObserver.observe(document.body, { childList: true, subtree: true });
}

/**
 * Stops the MutationObserver.
 */
function stopObserver() {
  if (trapObserver) {
    trapObserver.disconnect();
    trapObserver = null;
  }
}

/* ===========================================================================
 * Bootstrap
 * =========================================================================== */

function init() {
  initSkipLinks();
  initEscapeHandler();
  initFromDataAttributes();
  startObserver();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}

/* ===========================================================================
 * Public API
 * =========================================================================== */

export {
  trapFocus,
  releaseFocus,
  saveFocus,
  restoreFocus,
  getFocusStackDepth,
  initRovingTabindex,
  destroyRovingTabindex,
  initSkipLinks,
  initFromDataAttributes,
  startObserver,
  stopObserver,
  getFocusableElements,
  FOCUSABLE_SELECTOR,
};
