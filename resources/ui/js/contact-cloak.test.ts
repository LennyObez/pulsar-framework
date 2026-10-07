/**
 * Pulsar contact-cloak reassembler — tests
 *
 * The reassembler turns two base64 payloads into a mailto:/tel: href. base64
 * decodes to arbitrary bytes, so these cover what happens when the payload is
 * not the address the author meant: the href is written only for a value the
 * script can vouch for, and the anchor otherwise keeps the fallback text it was
 * served with.
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

async function reassemble(): Promise<void> {
  vi.resetModules();
  // Side-effect-only reassembler (untyped plain-JS module); imported to run its
  // DOM initialisation.
  // @ts-expect-error -- no declaration file for the untyped reassembler module
  await import('./contact-cloak.js');
}

function cloakMail(user: string, domain: string): HTMLAnchorElement {
  document.body.innerHTML =
    '<a class="pulsar-cloak-mail" data-u="' +
    btoa(user) +
    '" data-d="' +
    btoa(domain) +
    '">email</a>';

  return document.querySelector('a')!;
}

function cloakTel(number: string): HTMLAnchorElement {
  document.body.innerHTML = '<a class="pulsar-cloak-tel" data-n="' + btoa(number) + '">call</a>';

  return document.querySelector('a')!;
}

describe('contact-cloak mail', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('rebuilds a plain address', async () => {
    const el = cloakMail('hello', 'example.com');
    await reassemble();

    expect(el.getAttribute('href')).toBe('mailto:hello@example.com');
    expect(el.textContent).toBe('hello@example.com');
    expect(el.hasAttribute('data-u')).toBe(false);
    expect(el.hasAttribute('data-d')).toBe(false);
  });

  it('percent-encodes a plus-addressed local part and shows it unencoded', async () => {
    const el = cloakMail('news+team', 'example.com');
    await reassemble();

    // RFC 6068 §2: the addr-spec travels percent-encoded and the mail handler
    // decodes it, so the link and the label say the same address.
    expect(el.getAttribute('href')).toBe('mailto:news%2Bteam@example.com');
    expect(el.textContent).toBe('news+team@example.com');
  });

  it('refuses a payload that opens a mailto header field section', async () => {
    const el = cloakMail('victim@example.com?subject=Urgent&body=Wire+now', 'evil.example');
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
    expect(el.textContent).toBe('email');
    // The payload is left in place: nothing was reassembled from it.
    expect(el.hasAttribute('data-u')).toBe(true);
  });

  it('refuses a payload carrying a scheme', async () => {
    const el = cloakMail('javascript:alert(1)//', 'example.com');
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
    expect(el.textContent).toBe('email');
  });

  it('refuses a domain without a dot', async () => {
    const el = cloakMail('hello', 'localhost');
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
  });

  it('refuses an empty payload', async () => {
    document.body.innerHTML = '<a class="pulsar-cloak-mail" data-u="" data-d="">email</a>';
    const el = document.querySelector('a')!;
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
    expect(el.textContent).toBe('email');
  });

  it('refuses a payload that is not base64', async () => {
    document.body.innerHTML =
      '<a class="pulsar-cloak-mail" data-u="!!!not base64!!!" data-d="ZXhhbXBsZS5jb20=">email</a>';
    const el = document.querySelector('a')!;
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
    expect(el.textContent).toBe('email');
  });
});

describe('contact-cloak tel', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('dials the normalised number and shows the spacing the author wrote', async () => {
    const el = cloakTel('+32 470 12 34 56');
    await reassemble();

    expect(el.getAttribute('href')).toBe('tel:+32470123456');
    expect(el.textContent).toBe('+32 470 12 34 56');
    expect(el.hasAttribute('data-n')).toBe(false);
  });

  it('keeps the visual separators RFC 3966 permits', async () => {
    const el = cloakTel('+1 (234) 567-8900');
    await reassemble();

    expect(el.getAttribute('href')).toBe('tel:+1(234)567-8900');
  });

  it('refuses a payload carrying a scheme', async () => {
    const el = cloakTel('javascript:alert(1)');
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
    expect(el.textContent).toBe('call');
  });

  it('refuses a plus that is not the leading character', async () => {
    const el = cloakTel('12+34');
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
  });

  it('refuses a payload with no digit in it', async () => {
    const el = cloakTel('()-.');
    await reassemble();

    expect(el.hasAttribute('href')).toBe(false);
  });
});
