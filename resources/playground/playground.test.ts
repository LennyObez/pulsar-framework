/**
 * Pulsar UI Playground — preview source tests
 *
 * The toolbar's source selector points an iframe at a page, so whatever the
 * selector yields is a URL the playground navigates its own origin to. These
 * boot the real index.html markup and drive the selector, including the case
 * the markup does not contain: an option somebody else put there.
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
// The shipped page itself, so the test cannot drift from the markup a developer
// actually opens.
// @ts-expect-error -- Vite ?raw import; the toolchain has no declaration for it
import rawPage from './index.html?raw';

const page: string = rawPage;

/**
 * The shipped page body, minus its <script> tags: the module under test is
 * imported directly, and the editor it loads alongside is stubbed.
 */
const PAGE_BODY = page
  .slice(page.indexOf('<body>') + '<body>'.length, page.indexOf('</body>'))
  .replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '');

interface EditorStub {
  init: (options: unknown) => void;
  getValue: () => string;
  setValue: (value: string) => void;
}

function jsonResponse(body: unknown): Promise<{ ok: boolean; json: () => Promise<unknown> }> {
  return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
}

/** Answer the four endpoints the playground calls while it boots. */
function stubFetch(): void {
  vi.stubGlobal('fetch', (url: string) => {
    if (url === '/api/csrf-token') return jsonResponse({ token: 'test-token' });
    if (url === '/api/base-css') return jsonResponse({ css: ':root{}' });
    if (url === '/api/themes') return jsonResponse(['default']);

    return jsonResponse({ css: '' });
  });
}

async function boot(): Promise<void> {
  document.body.innerHTML = PAGE_BODY;

  const editor: EditorStub = { init: () => {}, getValue: () => '', setValue: () => {} };
  vi.stubGlobal('PulsarEditor', editor);
  stubFetch();

  vi.resetModules();
  // Side-effect-only playground controller (untyped plain-JS module); imported
  // to register its DOMContentLoaded initialisation.
  // @ts-expect-error -- no declaration file for the untyped playground module
  await import('./playground.js');

  document.dispatchEvent(new Event('DOMContentLoaded'));
  // Let the boot fetch chain settle so nothing lands mid-assertion.
  await new Promise((resolve) => setTimeout(resolve, 0));
}

function selector(): HTMLSelectElement {
  return document.getElementById('preview-source') as HTMLSelectElement;
}

function frame(): HTMLIFrameElement {
  return document.getElementById('catalog-frame') as HTMLIFrameElement;
}

function title(): string {
  return document.querySelector('.pg-preview-pane__title')!.textContent ?? '';
}

function toast(): string {
  return document.getElementById('pg-toast')!.textContent ?? '';
}

function choose(value: string): void {
  selector().value = value;
  selector().dispatchEvent(new Event('change'));
}

describe('playground preview source', () => {
  beforeEach(async () => {
    await boot();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('switches the frame and its heading together', () => {
    choose('/preview/forum');

    expect(frame().getAttribute('src')).toBe('/preview/forum');
    expect(title()).toBe('Forum');
  });

  it('names every source the shipped markup offers', () => {
    const offered = Array.from(selector().options).map((option) => option.value);

    for (const value of offered) {
      choose(value);

      expect(frame().getAttribute('src')).toBe(value);
      expect(title()).not.toBe('');
      expect(toast()).not.toBe('Unknown preview source.');
    }
  });

  it('refuses a source the playground does not serve', () => {
    const before = frame().getAttribute('src');
    const rogue = document.createElement('option');
    rogue.value = 'javascript:alert(document.domain)';
    selector().appendChild(rogue);

    choose('javascript:alert(document.domain)');

    expect(frame().getAttribute('src')).toBe(before);
    expect(toast()).toBe('Unknown preview source.');
  });

  it('refuses a path outside the playground', () => {
    const before = frame().getAttribute('src');
    const rogue = document.createElement('option');
    rogue.value = 'https://evil.example/preview/admin';
    selector().appendChild(rogue);

    choose('https://evil.example/preview/admin');

    expect(frame().getAttribute('src')).toBe(before);
    expect(toast()).toBe('Unknown preview source.');
  });
});
