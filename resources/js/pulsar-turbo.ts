/**
 * Pulsar Turbo — Frames + Streams client runtime.
 *
 * Handles <pulsar-frame> elements for scoped navigation and
 * <pulsar-stream> elements for server-pushed DOM mutations.
 *
 * SECURITY NOTE: Frame content and stream mutations come exclusively
 * from the same-origin Pulsar server. The server is responsible for
 * output escaping (via Pulsar's HtmlEscaper). All fetch requests
 * include same-origin credentials and CORS applies. This follows
 * the same security model as Hotwire Turbo.
 *
 * Usage:
 *   <!-- Frame: independently navigable section -->
 *   <pulsar-frame id="comments" src="/comments?page=1">
 *     Loading...
 *   </pulsar-frame>
 *
 *   <!-- Stream: server pushes DOM updates -->
 *   <pulsar-stream action="append" target="messages">
 *     <template><div>New message</div></template>
 *   </pulsar-stream>
 */

interface TurboConfig {
  frameSelector: string;
  streamSelector: string;
  progressBar: boolean;
  formSubmissions: boolean;
}

const DEFAULT_CONFIG: TurboConfig = {
  frameSelector: 'pulsar-frame',
  streamSelector: 'pulsar-stream',
  progressBar: true,
  formSubmissions: true,
};

type StreamAction = 'append' | 'prepend' | 'replace' | 'update' | 'remove' | 'before' | 'after';

const VALID_STREAM_ACTIONS = new Set<string>([
  'append',
  'prepend',
  'replace',
  'update',
  'remove',
  'before',
  'after',
]);

class PulsarTurbo {
  private config: TurboConfig;

  constructor(config: Partial<TurboConfig> = {}) {
    this.config = { ...DEFAULT_CONFIG, ...config };
    this.init();
  }

  private init(): void {
    this.processFrames(document);
    this.observeStreams();

    if (this.config.formSubmissions) {
      document.addEventListener('submit', (e) => this.handleFormSubmit(e));
    }

    document.addEventListener('click', (e) => this.handleLinkClick(e));

    new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node instanceof HTMLElement) {
            this.processFrames(node);
            this.processStreams(node);
          }
        }
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  private processFrames(root: Document | HTMLElement): void {
    const frames = root.querySelectorAll<HTMLElement>(this.config.frameSelector);

    for (const frame of frames) {
      if (frame.dataset.turboInitialized) continue;
      frame.dataset.turboInitialized = 'true';

      const src = frame.getAttribute('src');
      const loading = frame.getAttribute('loading');

      if (src && loading === 'lazy') {
        this.observeLazyFrame(frame, src);
      } else if (src) {
        void this.loadFrame(frame, src);
      }
    }
  }

  private observeLazyFrame(frame: HTMLElement, src: string): void {
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            observer.unobserve(entry.target);
            void this.loadFrame(entry.target as HTMLElement, src);
          }
        }
      },
      { threshold: 0.1 },
    );
    observer.observe(frame);
  }

  private async loadFrame(frame: HTMLElement, url: string): Promise<void> {
    const frameId = frame.id;
    if (!frameId) return;

    frame.setAttribute('aria-busy', 'true');
    frame.dispatchEvent(new CustomEvent('turbo:before-fetch', { bubbles: true }));

    try {
      const response = await fetch(url, {
        headers: {
          'Turbo-Frame': frameId,
          Accept: 'text/html',
        },
      });

      const html = await response.text();

      // Extract matching frame content from response using DOMParser
      // (safe: no script execution in DOMParser-parsed documents)
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      const matchingFrame = doc.querySelector<HTMLElement>(
        `${this.config.frameSelector}#${CSS.escape(frameId)}`,
      );

      // Apply trusted server content to the frame
      this.applyFrameContent(frame, matchingFrame?.childNodes ?? this.parseFragment(html));

      frame.removeAttribute('src');
      frame.setAttribute('aria-busy', 'false');

      frame.dispatchEvent(new CustomEvent('turbo:frame-load', { bubbles: true }));

      this.processFrames(frame);
    } catch (error) {
      frame.setAttribute('aria-busy', 'false');
      frame.dispatchEvent(
        new CustomEvent('turbo:frame-error', {
          detail: { error },
          bubbles: true,
        }),
      );
    }
  }

  /**
   * Safely replace frame contents with trusted server-rendered nodes.
   * Uses DOM node manipulation instead of innerHTML assignment.
   */
  private applyFrameContent(frame: HTMLElement, sourceNodes: NodeList): void {
    // Clear existing children
    while (frame.firstChild) {
      frame.removeChild(frame.firstChild);
    }

    // Import and append each node
    for (const node of Array.from(sourceNodes)) {
      frame.appendChild(document.importNode(node, true));
    }
  }

  /**
   * Parse an HTML string into a NodeList using DOMParser (no script execution).
   */
  private parseFragment(html: string): NodeList {
    const parser = new DOMParser();
    const doc = parser.parseFromString(`<body>${html}</body>`, 'text/html');
    return doc.body.childNodes;
  }

  private handleLinkClick(event: MouseEvent): void {
    const link = (event.target as HTMLElement).closest('a');
    if (!link) return;
    if (!(link instanceof HTMLAnchorElement)) return;

    if (event.ctrlKey || event.metaKey || event.shiftKey || link.target === '_blank') {
      return;
    }

    const frame = link.closest<HTMLElement>(this.config.frameSelector);
    if (!frame) return;

    const href = link.getAttribute('href');
    if (!href || href.startsWith('#')) return;

    const frameTarget = link.getAttribute('data-turbo-frame');
    if (frameTarget === '_top') return;

    // Same-origin check
    if (link.origin !== window.location.origin) return;

    event.preventDefault();

    const targetFrameId = frameTarget ?? frame.id;
    const targetFrame = document.getElementById(targetFrameId) ?? frame;

    void this.loadFrame(targetFrame, href);
  }

  private handleFormSubmit(event: SubmitEvent): void {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const frame = form.closest<HTMLElement>(this.config.frameSelector);
    if (!frame) return;

    const frameTarget = form.getAttribute('data-turbo-frame');
    if (frameTarget === '_top') return;

    event.preventDefault();

    const targetFrameId = frameTarget ?? frame.id;
    const targetFrame = document.getElementById(targetFrameId) ?? frame;

    void this.submitFormToFrame(form, targetFrame);
  }

  private async submitFormToFrame(form: HTMLFormElement, frame: HTMLElement): Promise<void> {
    const frameId = frame.id;
    if (!frameId) return;

    frame.setAttribute('aria-busy', 'true');

    const formData = new FormData(form);
    const method = (form.method ?? 'POST').toUpperCase();
    const url = form.action || window.location.href;

    const headers: Record<string, string> = {
      'Turbo-Frame': frameId,
      Accept: 'text/vnd.turbo-stream.html, text/html',
    };

    const fetchOptions: RequestInit = { method, headers };

    if (method === 'GET') {
      const params = new URLSearchParams(formData as unknown as Record<string, string>);
      const separator = url.includes('?') ? '&' : '?';
      void this.loadFrame(frame, `${url}${separator}${params}`);
      return;
    }

    fetchOptions.body = formData;

    try {
      const response = await fetch(url, fetchOptions);
      const contentType = response.headers.get('Content-Type') ?? '';
      const html = await response.text();

      if (contentType.includes('text/vnd.turbo-stream.html')) {
        this.processStreamHtml(html);
      } else {
        // Apply response content to frame using safe DOM methods
        this.applyFrameContent(frame, this.extractFrameContent(html, frameId));
      }

      frame.setAttribute('aria-busy', 'false');
    } catch (error) {
      frame.setAttribute('aria-busy', 'false');
      frame.dispatchEvent(
        new CustomEvent('turbo:frame-error', {
          detail: { error },
          bubbles: true,
        }),
      );
    }
  }

  /**
   * Extract matching frame content or parse full HTML as fragment.
   * Uses DOMParser which does not execute scripts.
   */
  private extractFrameContent(html: string, frameId: string): NodeList {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const matchingFrame = doc.querySelector<HTMLElement>(
      `${this.config.frameSelector}#${CSS.escape(frameId)}`,
    );

    return matchingFrame?.childNodes ?? doc.body.childNodes;
  }

  private observeStreams(): void {
    this.processStreams(document.body);
  }

  private processStreams(root: HTMLElement): void {
    const streams = root.querySelectorAll<HTMLElement>(this.config.streamSelector);
    for (const stream of streams) {
      this.executeStream(stream);
    }
  }

  connectWebSocket(url: string): WebSocket {
    const ws = new WebSocket(url);

    ws.addEventListener('message', (event: MessageEvent) => {
      const html = typeof event.data === 'string' ? event.data : '';
      if (html.includes('<pulsar-stream')) {
        this.processStreamHtml(html);
      }
    });

    return ws;
  }

  private processStreamHtml(html: string): void {
    const parser = new DOMParser();
    const doc = parser.parseFromString(`<body>${html}</body>`, 'text/html');
    const streams = doc.querySelectorAll<HTMLElement>(this.config.streamSelector);
    for (const stream of streams) {
      this.executeStream(stream);
    }
  }

  /**
   * Execute a single stream element as a DOM mutation.
   *
   * Stream content is trusted server HTML parsed via DOMParser
   * (no script execution). DOM mutations use safe node operations.
   */
  private executeStream(stream: HTMLElement): void {
    const action = stream.getAttribute('action');
    const targetId = stream.getAttribute('target');

    if (!action || !targetId || !VALID_STREAM_ACTIONS.has(action)) return;

    const target = document.getElementById(targetId);
    if (!target && action !== 'remove') return;

    const template = stream.querySelector('template');
    const contentNodes = template
      ? Array.from(template.content.childNodes).map((n) => document.importNode(n, true))
      : [];

    switch (action as StreamAction) {
      case 'append':
        for (const node of contentNodes) target!.appendChild(node);
        break;
      case 'prepend':
        for (const node of contentNodes.reverse()) target!.insertBefore(node, target!.firstChild);
        break;
      case 'replace':
        if (target!.parentNode) {
          for (const node of contentNodes) target!.parentNode.insertBefore(node, target!);
          target!.remove();
        }
        break;
      case 'update':
        while (target!.firstChild) target!.removeChild(target!.firstChild);
        for (const node of contentNodes) target!.appendChild(node);
        break;
      case 'remove':
        target?.remove();
        break;
      case 'before':
        if (target!.parentNode) {
          for (const node of contentNodes) target!.parentNode.insertBefore(node, target!);
        }
        break;
      case 'after':
        if (target!.parentNode) {
          const ref = target!.nextSibling;
          for (const node of contentNodes) target!.parentNode.insertBefore(node, ref);
        }
        break;
    }

    stream.remove();

    document.dispatchEvent(
      new CustomEvent('turbo:stream', {
        detail: { action, target: targetId },
        bubbles: true,
      }),
    );
  }
}

export { PulsarTurbo, type TurboConfig, type StreamAction };
