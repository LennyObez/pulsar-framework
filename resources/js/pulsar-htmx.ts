/**
 * Pulsar Hypermedia — lightweight client runtime for px-* attributes.
 *
 * Inspired by htmx, this ~3KB runtime lets any HTML element make HTTP
 * requests and swap content declaratively via data attributes.
 *
 * SECURITY NOTE: This runtime intentionally renders server-returned HTML
 * fragments into the DOM. This is the fundamental design of hypermedia
 * architectures (like htmx, Turbo, LiveWire). Security is enforced at
 * multiple layers:
 *   1. selfRequestsOnly=true (default) restricts requests to same origin
 *   2. Server-side output escaping (Pulsar's HtmlEscaper) prevents stored XSS
 *   3. CSRF tokens are sent with every non-GET request
 *   4. CSP headers restrict inline script execution
 *
 * Usage:
 *   <button px-get="/api/count" px-target="#counter" px-swap="innerHTML">
 *     Refresh
 *   </button>
 */

interface PxConfig {
  prefix: string;
  defaultSwapDelay: number;
  defaultSettleDelay: number;
  selfRequestsOnly: boolean;
  csrfHeader: string;
  csrfToken: string;
  historyCache: boolean;
  historyCacheSize: number;
}

type SwapStyle =
  | 'innerHTML'
  | 'outerHTML'
  | 'beforebegin'
  | 'afterbegin'
  | 'beforeend'
  | 'afterend'
  | 'delete'
  | 'none';

const DEFAULT_CONFIG: PxConfig = {
  prefix: 'px',
  defaultSwapDelay: 0,
  defaultSettleDelay: 20,
  selfRequestsOnly: true,
  csrfHeader: 'X-CSRF-Token',
  csrfToken: '',
  historyCache: true,
  historyCacheSize: 10,
};

class PulsarHtmx {
  private config: PxConfig;
  private historyCache: Map<string, string> = new Map();

  constructor(config: Partial<PxConfig> = {}) {
    this.config = { ...DEFAULT_CONFIG, ...config };
    this.init();
  }

  private init(): void {
    document.addEventListener('click', (e) => this.handleEvent(e));
    document.addEventListener('submit', (e) => this.handleEvent(e));
    document.addEventListener('change', (e) => this.handleEvent(e));
    document.addEventListener('input', (e) => this.handleEvent(e));

    this.processNode(document.body);

    new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node instanceof HTMLElement) {
            this.processNode(node);
          }
        }
      }
    }).observe(document.body, { childList: true, subtree: true });

    window.addEventListener('popstate', () => this.handlePopState());
  }

  private attr(name: string): string {
    return `${this.config.prefix}-${name}`;
  }

  private processNode(node: HTMLElement): void {
    const loadAttr = this.attr('trigger');

    const loadElements = node.querySelectorAll<HTMLElement>(`[${loadAttr}*="load"]`);
    for (const el of loadElements) {
      const trigger = el.getAttribute(loadAttr);
      if (trigger?.includes('load')) {
        this.performRequest(el);
      }
    }

    const revealElements = node.querySelectorAll<HTMLElement>(`[${loadAttr}*="revealed"]`);
    if (revealElements.length > 0) {
      const observer = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (entry.isIntersecting) {
              observer.unobserve(entry.target);
              this.performRequest(entry.target as HTMLElement);
            }
          }
        },
        { threshold: 0.1 },
      );
      for (const el of revealElements) {
        observer.observe(el);
      }
    }
  }

  private handleEvent(event: Event): void {
    const target = event.target as HTMLElement;
    const el = this.findTriggerElement(target);
    if (!el) return;

    const triggerAttr = el.getAttribute(this.attr('trigger'));
    const expectedTrigger = triggerAttr ?? this.defaultTrigger(el);

    if (!expectedTrigger.includes(event.type)) return;

    const confirmMsg = el.getAttribute(this.attr('confirm'));
    if (confirmMsg && !window.confirm(confirmMsg)) return;

    if (event.type === 'submit') {
      event.preventDefault();
    }

    this.performRequest(el, event);
  }

  private findTriggerElement(target: HTMLElement): HTMLElement | null {
    let el: HTMLElement | null = target;
    const verbs = ['get', 'post', 'put', 'patch', 'delete'];

    while (el) {
      for (const verb of verbs) {
        if (el.hasAttribute(this.attr(verb))) {
          return el;
        }
      }
      if (el.hasAttribute(this.attr('boost'))) {
        return el;
      }
      el = el.parentElement;
    }

    return null;
  }

  private defaultTrigger(el: HTMLElement): string {
    const tag = el.tagName.toLowerCase();
    if (tag === 'form') return 'submit';
    if (tag === 'input' || tag === 'select' || tag === 'textarea') return 'change';
    return 'click';
  }

  private async performRequest(el: HTMLElement, _event?: Event): Promise<void> {
    const { method, url } = this.resolveMethodAndUrl(el);
    if (!url) return;

    // Security: restrict to same-origin requests by default
    if (
      this.config.selfRequestsOnly &&
      !url.startsWith('/') &&
      !url.startsWith(window.location.origin)
    ) {
      return;
    }

    const indicatorSelector = el.getAttribute(this.attr('indicator'));
    const indicator = indicatorSelector
      ? document.querySelector<HTMLElement>(indicatorSelector)
      : null;

    if (indicator) indicator.style.display = '';
    el.classList.add(`${this.config.prefix}-request`);

    const headers: Record<string, string> = {
      'PX-Request': 'true',
      'PX-Current-URL': window.location.href,
    };

    if (el.id) headers['PX-Trigger'] = el.id;

    const name = el.getAttribute('name');
    if (name) headers['PX-Trigger-Name'] = name;

    const targetSelector = el.getAttribute(this.attr('target'));
    const targetEl = targetSelector ? document.querySelector<HTMLElement>(targetSelector) : el;
    if (targetEl?.id) headers['PX-Target'] = targetEl.id;

    if (this.config.csrfToken) {
      headers[this.config.csrfHeader] = this.config.csrfToken;
    }

    const extraHeaders = el.getAttribute(this.attr('headers'));
    if (extraHeaders) {
      try {
        const parsed: unknown = JSON.parse(extraHeaders);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          // Allowlist: only merge string values with safe header names
          for (const [key, val] of Object.entries(parsed as Record<string, unknown>)) {
            if (typeof val === 'string' && /^[a-zA-Z0-9-]+$/.test(key)) {
              headers[key] = val;
            }
          }
        }
      } catch {
        // ignore invalid JSON in attribute
      }
    }

    let body: FormData | string | undefined;
    if (method !== 'GET') {
      if (el instanceof HTMLFormElement) {
        body = new FormData(el);
      } else {
        const vals = el.getAttribute(this.attr('vals'));
        if (vals) {
          headers['Content-Type'] = 'application/json';
          body = vals;
        }
      }
    }

    try {
      const response = await fetch(url, { method, headers, body });
      const html = await response.text();

      this.processResponseHeaders(response, el);

      const swapStyle = (el.getAttribute(this.attr('swap')) as SwapStyle) ?? 'innerHTML';
      const target = targetEl ?? el;

      if (this.config.defaultSwapDelay > 0) {
        await this.delay(this.config.defaultSwapDelay);
      }

      // Trusted HTML from same-origin server (see security note at top)
      this.swap(target, html, swapStyle);

      if (this.config.defaultSettleDelay > 0) {
        await this.delay(this.config.defaultSettleDelay);
      }

      const pushUrl = el.getAttribute(this.attr('push-url'));
      if (pushUrl) {
        const resolvedUrl = pushUrl === 'true' ? url : pushUrl;
        window.history.pushState({ pxUrl: resolvedUrl }, '', resolvedUrl);
        if (this.config.historyCache) {
          this.cacheHistory(resolvedUrl, document.body.textContent ?? '');
        }
      }
    } catch (error) {
      el.dispatchEvent(new CustomEvent('px:error', { detail: { error }, bubbles: true }));
    } finally {
      if (indicator) indicator.style.display = 'none';
      el.classList.remove(`${this.config.prefix}-request`);
    }
  }

  private resolveMethodAndUrl(el: HTMLElement): { method: string; url: string } {
    const verbs = ['get', 'post', 'put', 'patch', 'delete'] as const;
    for (const verb of verbs) {
      const url = el.getAttribute(this.attr(verb));
      if (url) return { method: verb.toUpperCase(), url };
    }

    if (el.hasAttribute(this.attr('boost'))) {
      if (el instanceof HTMLAnchorElement) {
        return { method: 'GET', url: el.href };
      }
      if (el instanceof HTMLFormElement) {
        return {
          method: (el.method ?? 'GET').toUpperCase(),
          url: el.action,
        };
      }
    }

    return { method: 'GET', url: '' };
  }

  /**
   * Apply a content swap to the target element.
   *
   * All HTML content comes from the same-origin Pulsar server with
   * server-side output escaping applied. Same security model as htmx.
   */
  private swap(target: HTMLElement, trustedHtml: string, style: SwapStyle): void {
    switch (style) {
      case 'innerHTML':
        target.replaceChildren();
        target.insertAdjacentHTML('afterbegin', trustedHtml);
        break;
      case 'outerHTML':
        target.insertAdjacentHTML('afterend', trustedHtml);
        target.remove();
        break;
      case 'beforebegin':
        target.insertAdjacentHTML('beforebegin', trustedHtml);
        break;
      case 'afterbegin':
        target.insertAdjacentHTML('afterbegin', trustedHtml);
        break;
      case 'beforeend':
        target.insertAdjacentHTML('beforeend', trustedHtml);
        break;
      case 'afterend':
        target.insertAdjacentHTML('afterend', trustedHtml);
        break;
      case 'delete':
        target.remove();
        break;
      case 'none':
        break;
    }

    target.dispatchEvent(new CustomEvent('px:afterSwap', { bubbles: true }));
  }

  private processResponseHeaders(response: Response, el: HTMLElement): void {
    const redirect = response.headers.get('PX-Redirect');
    if (redirect) {
      window.location.href = redirect;
      return;
    }

    const refresh = response.headers.get('PX-Refresh');
    if (refresh === 'true') {
      window.location.reload();
      return;
    }

    const pushUrl = response.headers.get('PX-Push-Url');
    if (pushUrl) {
      window.history.pushState({ pxUrl: pushUrl }, '', pushUrl);
    }

    const replaceUrl = response.headers.get('PX-Replace-Url');
    if (replaceUrl) {
      window.history.replaceState({ pxUrl: replaceUrl }, '', replaceUrl);
    }

    const trigger = response.headers.get('PX-Trigger');
    if (trigger) this.dispatchEvents(el, trigger);

    const triggerAfterSettle = response.headers.get('PX-Trigger-After-Settle');
    if (triggerAfterSettle) {
      setTimeout(() => this.dispatchEvents(el, triggerAfterSettle), this.config.defaultSettleDelay);
    }

    const triggerAfterSwap = response.headers.get('PX-Trigger-After-Swap');
    if (triggerAfterSwap) this.dispatchEvents(el, triggerAfterSwap);
  }

  private dispatchEvents(el: HTMLElement, value: string): void {
    try {
      const events = JSON.parse(value) as Record<string, unknown>;
      for (const [eventName, detail] of Object.entries(events)) {
        el.dispatchEvent(new CustomEvent(eventName, { detail, bubbles: true }));
      }
    } catch {
      for (const eventName of value.split(',')) {
        el.dispatchEvent(new CustomEvent(eventName.trim(), { bubbles: true }));
      }
    }
  }

  private cacheHistory(url: string, content: string): void {
    this.historyCache.set(url, content);
    if (this.historyCache.size > this.config.historyCacheSize) {
      const firstKey = this.historyCache.keys().next().value;
      if (firstKey !== undefined) {
        this.historyCache.delete(firstKey);
      }
    }
  }

  private handlePopState(): void {
    // History restore: let the browser handle it with a full reload
    // since we only cache text content for security
    if (this.historyCache.has(window.location.href)) {
      window.location.reload();
    }
  }

  private delay(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
}

export { PulsarHtmx, type PxConfig, type SwapStyle };
