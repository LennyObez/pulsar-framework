export const FOCUSABLE_SELECTOR: string;

export function getFocusableElements(container: HTMLElement): HTMLElement[];

export function trapFocus(
  container: HTMLElement,
  options?: { autoFocus?: boolean; escapeDeactivates?: boolean },
): void;

export function releaseFocus(container: HTMLElement): void;

export function saveFocus(): void;

export function restoreFocus(): void;

export function getFocusStackDepth(): number;

export function initRovingTabindex(
  container: HTMLElement,
  options: {
    itemSelector: string;
    wrap?: boolean;
    vertical?: boolean;
  },
): void;

export function destroyRovingTabindex(container: HTMLElement): void;
