/**
 * Pulsar Inertia — TypeScript SPA bridge client.
 *
 * Manages page state, history, and partial reloads for the Inertia-style
 * server-routed SPA pattern. The server returns component names + props;
 * this client manages navigation and rendering via user-provided callbacks.
 *
 * SECURITY NOTE: This client does NOT perform any DOM manipulation itself.
 * All rendering is delegated to the user-supplied `setup` callback, which
 * is responsible for safely rendering component output. The client only
 * manages JSON page data, history, and navigation interception.
 *
 * Usage:
 *   const inertia = new PulsarInertia({
 *     resolve: (name) => import(`./pages/${name}.ts`),
 *     setup: ({ el, component, props }) => { component.mount(el, props); },
 *   });
 */

interface PageData {
  component: string;
  props: Record<string, unknown>;
  url: string;
  version: string;
}

interface ComponentModule {
  render(props: Record<string, unknown>): string | HTMLElement;
  mount?(el: HTMLElement, props: Record<string, unknown>): void;
}

interface InertiaConfig {
  resolve: (name: string) => Promise<ComponentModule>;
  setup: (context: {
    el: HTMLElement;
    component: ComponentModule;
    props: Record<string, unknown>;
  }) => void;
  element?: string | HTMLElement;
  progress?: boolean;
  preserveScroll?: boolean;
}

interface VisitOptions {
  method?: string;
  data?: Record<string, unknown>;
  headers?: Record<string, string>;
  preserveScroll?: boolean;
  preserveState?: boolean;
  replace?: boolean;
  only?: string[];
  except?: string[];
}

class PulsarInertia {
  private config: InertiaConfig;
  private currentPage: PageData | null = null;
  private rootEl: HTMLElement;

  constructor(config: InertiaConfig) {
    this.config = config;
    this.rootEl = this.resolveRootElement();

    const initialPage = this.readInitialPage();
    if (initialPage) {
      void this.renderPage(initialPage);
    }

    window.addEventListener('popstate', (e) => {
      const state = e.state as PageData | null;
      if (state?.component) {
        void this.renderPage(state);
      }
    });

    this.interceptLinks();
    this.interceptForms();
  }

  async visit(url: string, options: VisitOptions = {}): Promise<void> {
    const method = (options.method ?? 'GET').toUpperCase();

    const headers: Record<string, string> = {
      'X-Inertia': 'true',
      Accept: 'text/html, application/xhtml+xml',
    };

    // Merge user-provided headers safely (string values only)
    if (options.headers) {
      for (const [key, val] of Object.entries(options.headers)) {
        if (typeof val === 'string') {
          headers[key] = val;
        }
      }
    }

    if (this.currentPage?.version) {
      headers['X-Inertia-Version'] = this.currentPage.version;
    }

    if (options.only?.length) {
      headers['X-Inertia-Partial-Data'] = options.only.join(',');
      headers['X-Inertia-Partial-Component'] = this.currentPage?.component ?? '';
    }

    if (options.except?.length) {
      headers['X-Inertia-Partial-Except'] = options.except.join(',');
    }

    const fetchOptions: RequestInit = { method, headers };

    if (method !== 'GET' && options.data) {
      headers['Content-Type'] = 'application/json';
      fetchOptions.body = JSON.stringify(options.data);
    }

    if (this.config.progress) {
      this.showProgress();
    }

    try {
      const response = await fetch(url, fetchOptions);

      if (response.status === 409) {
        const location = response.headers.get('X-Inertia-Location');
        if (location) {
          window.location.href = location;
          return;
        }
      }

      if (!response.ok) {
        window.dispatchEvent(
          new CustomEvent('inertia:error', {
            detail: { status: response.status },
          }),
        );
        return;
      }

      const contentType = response.headers.get('Content-Type') ?? '';

      if (contentType.includes('application/json')) {
        const page = (await response.json()) as PageData;
        page.url = url;

        if (options.replace) {
          window.history.replaceState(page, '', page.url);
        } else {
          window.history.pushState(page, '', page.url);
        }

        await this.renderPage(page, options);
      } else {
        // Full HTML response — hard redirect
        window.location.href = url;
      }
    } finally {
      if (this.config.progress) {
        this.hideProgress();
      }
    }
  }

  reload(options: VisitOptions = {}): Promise<void> {
    return this.visit(this.currentPage?.url ?? window.location.href, {
      preserveState: true,
      preserveScroll: true,
      ...options,
    });
  }

  get page(): PageData | null {
    return this.currentPage;
  }

  private async renderPage(page: PageData, options: VisitOptions = {}): Promise<void> {
    window.dispatchEvent(new CustomEvent('inertia:before', { detail: { page } }));

    const component = await this.config.resolve(page.component);
    this.currentPage = page;

    // Rendering is delegated to user-provided setup callback
    this.config.setup({
      el: this.rootEl,
      component,
      props: page.props,
    });

    if (!options.preserveScroll && this.config.preserveScroll !== true) {
      window.scrollTo(0, 0);
    }

    window.dispatchEvent(new CustomEvent('inertia:navigate', { detail: { page } }));
  }

  private resolveRootElement(): HTMLElement {
    if (this.config.element instanceof HTMLElement) {
      return this.config.element;
    }

    const selector = this.config.element ?? '#app';
    const el = document.querySelector<HTMLElement>(selector);

    if (!el) {
      throw new Error(`Pulsar Inertia: Root element "${selector}" not found`);
    }

    return el;
  }

  private readInitialPage(): PageData | null {
    const dataAttr = this.rootEl.dataset.page;
    if (!dataAttr) return null;

    try {
      return JSON.parse(dataAttr) as PageData;
    } catch {
      return null;
    }
  }

  private interceptLinks(): void {
    document.addEventListener('click', (e) => {
      const link = (e.target as HTMLElement).closest('a');
      if (!link) return;
      if (!(link instanceof HTMLAnchorElement)) return;

      if (
        link.target === '_blank' ||
        link.hasAttribute('download') ||
        e.ctrlKey ||
        e.metaKey ||
        e.shiftKey
      ) {
        return;
      }

      const href = link.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('mailto:')) {
        return;
      }

      if (link.origin !== window.location.origin || link.hasAttribute('data-inertia-ignore')) {
        return;
      }

      e.preventDefault();
      void this.visit(href, {
        method: link.dataset.method ?? 'GET',
      });
    });
  }

  private interceptForms(): void {
    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (form.hasAttribute('data-inertia-ignore')) return;

      const actionUrl = form.action || window.location.href;
      try {
        if (new URL(actionUrl).origin !== window.location.origin) {
          return;
        }
      } catch {
        return;
      }

      e.preventDefault();

      const formData = new FormData(form);
      const data: Record<string, unknown> = {};
      formData.forEach((value, key) => {
        data[key] = value;
      });

      void this.visit(actionUrl, {
        method: form.method.toUpperCase(),
        data,
      });
    });
  }

  private showProgress(): void {
    let bar = document.getElementById('inertia-progress');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'inertia-progress';
      bar.style.cssText =
        'position:fixed;top:0;left:0;height:3px;background:#4f46e5;width:0;transition:width .3s;z-index:99999';
      document.body.appendChild(bar);
    }
    bar.style.width = '0';
    requestAnimationFrame(() => {
      bar!.style.width = '80%';
    });
  }

  private hideProgress(): void {
    const bar = document.getElementById('inertia-progress');
    if (bar) {
      bar.style.width = '100%';
      setTimeout(() => bar.remove(), 300);
    }
  }
}

export { PulsarInertia, type InertiaConfig, type PageData, type VisitOptions };
