/**
 * Pulsar Focus Manager — Test Suite
 *
 * Uses Vitest with jsdom environment to test DOM-based focus
 * trapping, restoration, roving tabindex, and skip link handling.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  trapFocus,
  releaseFocus,
  saveFocus,
  restoreFocus,
  getFocusStackDepth,
  initRovingTabindex,
  destroyRovingTabindex,
  getFocusableElements,
  FOCUSABLE_SELECTOR,
} from './focus-manager.js';

/**
 * Helper: creates an HTML structure and appends it to document.body.
 */
function createDOM(html: string): HTMLElement {
  const container = document.createElement('div');
  container.innerHTML = html;
  document.body.appendChild(container);
  return container;
}

/**
 * Helper: dispatches a real KeyboardEvent on the given target.
 */
function pressKey(
  target: EventTarget,
  key: string,
  options: Partial<KeyboardEventInit> = {},
): void {
  const event = new KeyboardEvent('keydown', {
    key,
    bubbles: true,
    cancelable: true,
    ...options,
  });
  target.dispatchEvent(event);
}

/**
 * Stubs getComputedStyle to return visible defaults.
 * jsdom does not compute styles from CSS, so we need this for
 * getFocusableElements to work.
 */
function stubComputedStyle(): void {
  vi.spyOn(window, 'getComputedStyle').mockReturnValue({
    display: 'block',
    visibility: 'visible',
  } as CSSStyleDeclaration);
}

describe('FOCUSABLE_SELECTOR', () => {
  it('should be a non-empty string', () => {
    expect(typeof FOCUSABLE_SELECTOR).toBe('string');
    expect(FOCUSABLE_SELECTOR.length).toBeGreaterThan(0);
  });
});

describe('getFocusableElements', () => {
  let root: HTMLElement;

  beforeEach(() => {
    stubComputedStyle();
    root = createDOM(`
      <div id="test-container">
        <a href="/link">Link</a>
        <button>Click me</button>
        <button disabled>Disabled</button>
        <input type="text" />
        <input type="hidden" />
        <textarea></textarea>
        <select><option>A</option></select>
        <div tabindex="0">Focusable div</div>
        <div tabindex="-1">Not tabbable</div>
        <div>Inert div</div>
      </div>
    `);
  });

  afterEach(() => {
    root.remove();
    vi.restoreAllMocks();
  });

  it('should find all focusable elements', () => {
    const container = root.querySelector('#test-container') as HTMLElement;
    const focusable = getFocusableElements(container);

    // Should include: a[href], enabled button, input[text], textarea, select, div[tabindex=0]
    // Should exclude: disabled button, input[hidden], div[tabindex=-1], inert div
    expect(focusable.length).toBe(6);
  });

  it('should exclude hidden elements', () => {
    const container = root.querySelector('#test-container') as HTMLElement;

    // Hide the link
    const link = container.querySelector('a') as HTMLElement;
    link.hidden = true;

    const focusable = getFocusableElements(container);
    expect(focusable).not.toContain(link);
  });

  it('should return empty array for container with no focusable elements', () => {
    const empty = createDOM('<div id="empty"><p>No interactive elements</p></div>');
    const container = empty.querySelector('#empty') as HTMLElement;

    const focusable = getFocusableElements(container);
    expect(focusable).toEqual([]);

    empty.remove();
  });
});

describe('trapFocus / releaseFocus', () => {
  let root: HTMLElement;

  beforeEach(() => {
    stubComputedStyle();
    root = createDOM(`
      <div id="trap-container">
        <button id="btn-first">First</button>
        <button id="btn-second">Second</button>
        <button id="btn-last">Last</button>
      </div>
    `);
  });

  afterEach(() => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    releaseFocus(container);
    root.remove();
    vi.restoreAllMocks();
  });

  it('should set data-focus-trap attribute on the container', () => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    trapFocus(container);
    expect(container.hasAttribute('data-focus-trap')).toBe(true);
  });

  it('should focus the first focusable element by default', () => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    const firstBtn = root.querySelector('#btn-first') as HTMLElement;
    trapFocus(container);
    expect(document.activeElement).toBe(firstBtn);
  });

  it('should not auto-focus when autoFocus is false', () => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    const beforeActive = document.activeElement;
    trapFocus(container, { autoFocus: false });
    expect(document.activeElement).toBe(beforeActive);
  });

  it('should cycle Tab from last to first element', () => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    const firstBtn = root.querySelector('#btn-first') as HTMLElement;
    const lastBtn = root.querySelector('#btn-last') as HTMLElement;

    trapFocus(container);
    lastBtn.focus();
    expect(document.activeElement).toBe(lastBtn);

    pressKey(container, 'Tab');
    expect(document.activeElement).toBe(firstBtn);
  });

  it('should cycle Shift+Tab from first to last element', () => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    const firstBtn = root.querySelector('#btn-first') as HTMLElement;
    const lastBtn = root.querySelector('#btn-last') as HTMLElement;

    trapFocus(container);
    firstBtn.focus();

    pressKey(container, 'Tab', { shiftKey: true });
    expect(document.activeElement).toBe(lastBtn);
  });

  it('should release focus trap and remove data attribute', () => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    trapFocus(container);
    expect(container.hasAttribute('data-focus-trap')).toBe(true);

    releaseFocus(container);
    expect(container.hasAttribute('data-focus-trap')).toBe(false);
  });

  it('should not activate twice on the same container', () => {
    const container = root.querySelector('#trap-container') as HTMLElement;
    trapFocus(container);
    const stackBefore = getFocusStackDepth();
    trapFocus(container); // second call — should be a no-op
    expect(getFocusStackDepth()).toBe(stackBefore);
  });

  it('should handle empty containers gracefully', () => {
    const empty = createDOM('<div id="empty-trap"></div>');
    const container = empty.querySelector('#empty-trap') as HTMLElement;

    // Should not throw
    trapFocus(container);

    // Container itself becomes focusable as a fallback
    expect(container.getAttribute('tabindex')).toBe('-1');

    releaseFocus(container);
    expect(container.hasAttribute('tabindex')).toBe(false);

    empty.remove();
  });
});

describe('Escape key handling in focus traps', () => {
  let root: HTMLElement;

  beforeEach(() => {
    stubComputedStyle();
    root = createDOM(`
      <div id="escape-container">
        <button id="trigger">Open</button>
        <div id="modal-trap">
          <button id="modal-btn">Inside</button>
        </div>
      </div>
    `);
  });

  afterEach(() => {
    const trap = root.querySelector('#modal-trap') as HTMLElement;
    releaseFocus(trap);
    root.remove();
    vi.restoreAllMocks();
  });

  it('should release trap and restore focus on Escape', () => {
    const trigger = root.querySelector('#trigger') as HTMLElement;
    const trap = root.querySelector('#modal-trap') as HTMLElement;
    const modalBtn = root.querySelector('#modal-btn') as HTMLElement;

    trigger.focus();
    trapFocus(trap);

    expect(document.activeElement).toBe(modalBtn);

    pressKey(trap, 'Escape');

    // Trap should be released
    expect(trap.hasAttribute('data-focus-trap')).toBe(false);

    // Focus should be restored to the trigger
    expect(document.activeElement).toBe(trigger);
  });

  it('should dispatch pui:focus-trap:escape event on Escape', () => {
    const trap = root.querySelector('#modal-trap') as HTMLElement;
    const handler = vi.fn();

    trap.addEventListener('pui:focus-trap:escape', handler);
    trapFocus(trap);

    pressKey(trap, 'Escape');

    expect(handler).toHaveBeenCalledTimes(1);
    trap.removeEventListener('pui:focus-trap:escape', handler);
  });

  it('should not release trap when escapeDeactivates is false', () => {
    const trap = root.querySelector('#modal-trap') as HTMLElement;
    trapFocus(trap, { escapeDeactivates: false });

    pressKey(trap, 'Escape');

    // Trap should still be active
    expect(trap.hasAttribute('data-focus-trap')).toBe(true);

    releaseFocus(trap);
  });
});

describe('saveFocus / restoreFocus', () => {
  let root: HTMLElement;

  beforeEach(() => {
    root = createDOM(`
      <div>
        <button id="btn-a">A</button>
        <button id="btn-b">B</button>
        <button id="btn-c">C</button>
      </div>
    `);
  });

  afterEach(() => {
    root.remove();
    // Drain the focus stack
    while (getFocusStackDepth() > 0) {
      restoreFocus();
    }
  });

  it('should save and restore focus correctly', () => {
    const btnA = root.querySelector('#btn-a') as HTMLElement;
    const btnB = root.querySelector('#btn-b') as HTMLElement;

    btnA.focus();
    saveFocus();

    btnB.focus();
    restoreFocus();

    expect(document.activeElement).toBe(btnA);
  });

  it('should support nested save/restore (stack behavior)', () => {
    const btnA = root.querySelector('#btn-a') as HTMLElement;
    const btnB = root.querySelector('#btn-b') as HTMLElement;
    const btnC = root.querySelector('#btn-c') as HTMLElement;

    btnA.focus();
    saveFocus();
    expect(getFocusStackDepth()).toBe(1);

    btnB.focus();
    saveFocus();
    expect(getFocusStackDepth()).toBe(2);

    btnC.focus();

    // Pop B
    restoreFocus();
    expect(document.activeElement).toBe(btnB);
    expect(getFocusStackDepth()).toBe(1);

    // Pop A
    restoreFocus();
    expect(document.activeElement).toBe(btnA);
    expect(getFocusStackDepth()).toBe(0);
  });

  it('should no-op when restoring with empty stack', () => {
    const before = document.activeElement;
    restoreFocus(); // Should not throw
    expect(document.activeElement).toBe(before);
  });

  it('should handle restored element that was removed from DOM', () => {
    const btnA = root.querySelector('#btn-a') as HTMLElement;
    btnA.focus();
    saveFocus();

    // Remove the element
    btnA.remove();

    // Should not throw, and should not change focus
    const before = document.activeElement;
    restoreFocus();
    expect(document.activeElement).toBe(before);
  });
});

describe('initRovingTabindex / destroyRovingTabindex', () => {
  let root: HTMLElement;

  beforeEach(() => {
    stubComputedStyle();
    root = createDOM(`
      <div id="toolbar" role="toolbar">
        <button id="tool-a">A</button>
        <button id="tool-b">B</button>
        <button id="tool-c">C</button>
      </div>
    `);
  });

  afterEach(() => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    destroyRovingTabindex(toolbar);
    root.remove();
    vi.restoreAllMocks();
  });

  it('should set data-roving-tab attribute', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    initRovingTabindex(toolbar, { itemSelector: 'button' });
    expect(toolbar.hasAttribute('data-roving-tab')).toBe(true);
  });

  it('should set first item to tabindex=0 and rest to tabindex=-1', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    initRovingTabindex(toolbar, { itemSelector: 'button' });

    const buttons = toolbar.querySelectorAll('button');
    expect(buttons[0]!.getAttribute('tabindex')).toBe('0');
    expect(buttons[1]!.getAttribute('tabindex')).toBe('-1');
    expect(buttons[2]!.getAttribute('tabindex')).toBe('-1');
  });

  it('should move focus forward with ArrowRight', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    const btnA = root.querySelector('#tool-a') as HTMLElement;
    const btnB = root.querySelector('#tool-b') as HTMLElement;

    initRovingTabindex(toolbar, { itemSelector: 'button' });
    btnA.focus();

    pressKey(toolbar, 'ArrowRight');
    expect(document.activeElement).toBe(btnB);
    expect(btnA.getAttribute('tabindex')).toBe('-1');
    expect(btnB.getAttribute('tabindex')).toBe('0');
  });

  it('should move focus backward with ArrowLeft', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    const btnA = root.querySelector('#tool-a') as HTMLElement;
    const btnB = root.querySelector('#tool-b') as HTMLElement;

    initRovingTabindex(toolbar, { itemSelector: 'button' });
    btnB.focus();
    // Manually set tabindex state for B to be active
    btnA.setAttribute('tabindex', '-1');
    btnB.setAttribute('tabindex', '0');

    pressKey(toolbar, 'ArrowLeft');
    expect(document.activeElement).toBe(btnA);
  });

  it('should wrap from last to first with ArrowRight', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    const btnA = root.querySelector('#tool-a') as HTMLElement;
    const btnC = root.querySelector('#tool-c') as HTMLElement;

    initRovingTabindex(toolbar, { itemSelector: 'button', wrap: true });

    // Move to last item
    btnC.focus();
    btnA.setAttribute('tabindex', '-1');
    btnC.setAttribute('tabindex', '0');

    pressKey(toolbar, 'ArrowRight');
    expect(document.activeElement).toBe(btnA);
  });

  it('should not wrap when wrap is false', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    const btnC = root.querySelector('#tool-c') as HTMLElement;

    initRovingTabindex(toolbar, { itemSelector: 'button', wrap: false });

    btnC.focus();
    toolbar.querySelectorAll('button').forEach((b) => b.setAttribute('tabindex', '-1'));
    btnC.setAttribute('tabindex', '0');

    pressKey(toolbar, 'ArrowRight');
    // Should stay on last item
    expect(document.activeElement).toBe(btnC);
  });

  it('should use ArrowUp/ArrowDown when vertical is true', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    const btnA = root.querySelector('#tool-a') as HTMLElement;
    const btnB = root.querySelector('#tool-b') as HTMLElement;

    initRovingTabindex(toolbar, { itemSelector: 'button', vertical: true });
    btnA.focus();

    pressKey(toolbar, 'ArrowDown');
    expect(document.activeElement).toBe(btnB);

    pressKey(toolbar, 'ArrowUp');
    expect(document.activeElement).toBe(btnA);
  });

  it('should jump to first with Home and last with End', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    const btnA = root.querySelector('#tool-a') as HTMLElement;
    const btnB = root.querySelector('#tool-b') as HTMLElement;
    const btnC = root.querySelector('#tool-c') as HTMLElement;

    initRovingTabindex(toolbar, { itemSelector: 'button' });
    btnB.focus();
    btnA.setAttribute('tabindex', '-1');
    btnB.setAttribute('tabindex', '0');

    pressKey(toolbar, 'End');
    expect(document.activeElement).toBe(btnC);

    pressKey(toolbar, 'Home');
    expect(document.activeElement).toBe(btnA);
  });

  it('should clean up on destroy', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    initRovingTabindex(toolbar, { itemSelector: 'button' });
    expect(toolbar.hasAttribute('data-roving-tab')).toBe(true);

    destroyRovingTabindex(toolbar);
    expect(toolbar.hasAttribute('data-roving-tab')).toBe(false);
  });

  it('should not initialize twice', () => {
    const toolbar = root.querySelector('#toolbar') as HTMLElement;
    initRovingTabindex(toolbar, { itemSelector: 'button' });
    initRovingTabindex(toolbar, { itemSelector: 'button' }); // no-op

    // Cleanup should still work normally
    destroyRovingTabindex(toolbar);
    expect(toolbar.hasAttribute('data-roving-tab')).toBe(false);
  });
});
