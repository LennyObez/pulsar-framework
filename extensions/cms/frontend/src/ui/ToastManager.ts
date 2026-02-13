/**
 * Singleton toast manager providing a global API for showing notifications.
 *
 * Auto-intercepts fetch responses to check for the X-CMS-Toast header,
 * and exposes convenience methods on `window.CmsToast`.
 */

import type { CmsToastContainer } from './ToastContainer.js';

type ToastType = 'success' | 'error' | 'info' | 'warning';

const DEFAULT_DURATIONS: Readonly<Record<ToastType, number>> = {
  success: 5000,
  error: 8000,
  info: 5000,
  warning: 6000,
};

const TOAST_HEADER = 'X-CMS-Toast';

class ToastManager {
  private container: CmsToastContainer | null = null;
  private interceptInstalled = false;

  /** Show a success toast. */
  success(message: string, duration?: number): void {
    this.show(message, 'success', duration ?? DEFAULT_DURATIONS.success);
  }

  /** Show an error toast. */
  error(message: string, duration?: number): void {
    this.show(message, 'error', duration ?? DEFAULT_DURATIONS.error);
  }

  /** Show an info toast. */
  info(message: string, duration?: number): void {
    this.show(message, 'info', duration ?? DEFAULT_DURATIONS.info);
  }

  /** Show a warning toast. */
  warning(message: string, duration?: number): void {
    this.show(message, 'warning', duration ?? DEFAULT_DURATIONS.warning);
  }

  /** Show a toast of the given type. */
  show(message: string, type: ToastType, duration: number): void {
    this.ensureContainer().addToast(message, type, duration);
  }

  /** Install a global fetch interceptor that reads X-CMS-Toast headers. */
  installFetchInterceptor(): void {
    if (this.interceptInstalled) return;
    this.interceptInstalled = true;

    const originalFetch = window.fetch;

    window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const response = await originalFetch.call(window, input, init);
      this.processResponse(response);
      return response;
    };
  }

  private processResponse(response: Response): void {
    const header = response.headers.get(TOAST_HEADER);
    if (header === null) return;

    try {
      const parsed: unknown = JSON.parse(header);
      if (!Array.isArray(parsed)) return;

      const VALID_TYPES: readonly string[] = ['success', 'error', 'info', 'warning'];

      for (const item of parsed) {
        if (typeof item === 'object' && item !== null && 'message' in item && 'type' in item) {
          const raw = item as Record<string, unknown>;
          const message = raw.message;
          const rawType = raw.type;
          const rawDuration = raw.duration;

          if (typeof message !== 'string' || typeof rawType !== 'string') {
            continue;
          }

          const type: ToastType = VALID_TYPES.includes(rawType) ? (rawType as ToastType) : 'info';
          this.show(
            message,
            type,
            typeof rawDuration === 'number' ? rawDuration : (DEFAULT_DURATIONS[type] ?? 5000),
          );
        }
      }
    } catch {
      // Silently ignore malformed toast headers
    }
  }

  private ensureContainer(): CmsToastContainer {
    if (this.container !== null) {
      return this.container;
    }

    let existing = document.querySelector<CmsToastContainer>('cms-toast-container');

    if (existing === null) {
      existing = document.createElement('cms-toast-container') as CmsToastContainer;
      document.body.appendChild(existing);
    }

    this.container = existing;
    return this.container;
  }
}

/** Singleton instance exported as the global CmsToast API. */
const CmsToast = new ToastManager();

// Auto-install fetch interceptor on module load
CmsToast.installFetchInterceptor();

// Expose on window for non-module scripts
declare global {
  interface Window {
    CmsToast: ToastManager;
  }
}

window.CmsToast = CmsToast;

export { CmsToast, ToastManager };
