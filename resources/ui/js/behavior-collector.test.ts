// @vitest-environment jsdom
/**
 * Pulsar Behavioural Signals — Collector tests
 *
 * Verifies the collector writes a compact, non-identifying signal blob into the
 * marked hidden field on submit, captures interaction/keydown/paste/webdriver,
 * and emits a clean "no interaction" blob when the form is submitted untouched.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

interface Blob {
  i: number;
  d: number;
  pe: number;
  wd: number;
  pr: number;
  kc: number;
}

async function loadCollector(): Promise<void> {
  vi.resetModules();
  // Side-effect-only collector (untyped plain-JS module); imported to run its
  // DOM initialisation.
  // @ts-expect-error -- no declaration file for the untyped collector module
  await import('./behavior-collector.js');
}

function setup(): { form: HTMLFormElement; field: HTMLInputElement } {
  document.body.innerHTML =
    '<form>' +
    '<input type="hidden" name="pulsar-bx" value="" data-pulsar-behavior>' +
    '<input type="text" name="title">' +
    '</form>';
  const form = document.querySelector('form')!;
  const field = document.querySelector<HTMLInputElement>('input[name="pulsar-bx"]')!;
  return { form, field };
}

function submittedBlob(field: HTMLInputElement): Blob {
  return JSON.parse(field.value) as Blob;
}

describe('behavior collector', () => {
  beforeEach(() => {
    vi.unstubAllGlobals();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('captures interaction and keystrokes into the hidden field on submit', async () => {
    const { form, field } = setup();
    await loadCollector();

    form.dispatchEvent(new Event('focusin', { bubbles: true }));
    for (let i = 0; i < 3; i++) {
      form.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
    }
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    const blob = submittedBlob(field);
    expect(blob.i).toBe(1);
    expect(blob.kc).toBe(3);
    expect(blob.d).toBeGreaterThanOrEqual(0);
  });

  it('emits a clean no-interaction blob when the form is untouched', async () => {
    const { form, field } = setup();
    await loadCollector();

    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    const blob = submittedBlob(field);
    expect(blob.i).toBe(0);
    expect(blob.kc).toBe(0);
    expect(blob.pr).toBe(0);
  });

  it('reflects navigator.webdriver', async () => {
    Object.defineProperty(navigator, 'webdriver', { value: true, configurable: true });
    const { form, field } = setup();
    await loadCollector();

    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(submittedBlob(field).wd).toBe(1);
    Object.defineProperty(navigator, 'webdriver', { value: false, configurable: true });
  });

  it('computes a paste ratio without storing the pasted content', async () => {
    const { form, field } = setup();
    await loadCollector();

    const paste = new Event('paste', { bubbles: true }) as Event & {
      clipboardData: { getData: (t: string) => string };
    };
    paste.clipboardData = { getData: () => 'hello world' };
    form.dispatchEvent(paste);
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    const blob = submittedBlob(field);
    // All entered characters came from a paste ⇒ ratio 1, and the blob holds no
    // text, only the ratio.
    expect(blob.pr).toBe(1);
    expect(field.value).not.toContain('hello');
  });
});
