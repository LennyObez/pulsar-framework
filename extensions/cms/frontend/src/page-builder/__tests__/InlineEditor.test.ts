/**
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest';
import { InlineEditor } from '../InlineEditor.js';

interface Internals {
  sanitize(): string;
  sanitizeToFragment(html: string): DocumentFragment | null;
}

/**
 * Builds an editor over a host whose markup the test controls, then reports what
 * sanitize() would emit. The markup goes in through the test, never through the
 * code under test, so nothing here parses an untrusted string.
 */
function emits(markup: string): string {
  const host = document.createElement('div');
  host.innerHTML = markup;
  document.body.appendChild(host);

  return (new InlineEditor(host, () => {}) as unknown as Internals).sanitize();
}

function internals(): Internals {
  const host = document.createElement('div');
  document.body.appendChild(host);

  return new InlineEditor(host, () => {}) as unknown as Internals;
}

describe('InlineEditor sanitizer', () => {
  it('keeps allowed inline formatting', () => {
    expect(emits('<b>bold</b> and <em>emphasis</em>')).toBe('<b>bold</b> and <em>emphasis</em>');
  });

  it('reduces a disallowed element to its text', () => {
    expect(emits('<div>plain</div>')).toBe('plain');
  });

  it('never lets a script survive as markup', () => {
    const out = emits('text<script>alert(1)</script>more');

    expect(out).not.toContain('<script');
    expect(out).toBe('textalert(1)more');
  });

  it('strips every attribute except href on an anchor', () => {
    expect(emits('<b onclick="alert(1)" class="x">t</b>')).toBe('<b>t</b>');
  });

  it('keeps an absolute https href', () => {
    expect(emits('<a href="https://example.com/a">l</a>')).toContain(
      'href="https://example.com/a"',
    );
  });

  it('keeps a rooted same-origin path', () => {
    expect(emits('<a href="/docs/intro">l</a>')).toContain('href="/docs/intro"');
  });

  it('drops a javascript: href', () => {
    expect(emits('<a href="javascript:alert(1)">l</a>')).not.toContain('javascript:');
  });

  // A leading slash alone does not mean same origin. Both of these resolve
  // off-site, and the rule that only tested for '/' admitted them.
  it('drops a protocol-relative href', () => {
    expect(emits('<a href="//evil.example/x">l</a>')).not.toContain('evil.example');
  });

  it('drops a backslash protocol-relative href', () => {
    expect(emits('<a href="/\\evil.example/x">l</a>')).not.toContain('evil.example');
  });

  it('drops a comment node', () => {
    expect(emits('a<!-- note -->b')).toBe('ab');
  });

  // Sanitizing an already sanitized string must be a no-op. When it is not,
  // the output changes meaning on a second pass, which is mutation XSS.
  it('is idempotent', () => {
    const hostile =
      '<div><a href="javascript:alert(1)">l</a><script>alert(2)</script><b>k</b></div>';
    const once = emits(hostile);

    expect(emits(once)).toBe(once);
  });

  // The paste path must never fall back to parsing the clipboard itself. Where
  // Element.setHTML is missing — as in this environment — it returns null and
  // the caller pastes plain text instead.
  it('refuses to build a fragment without a browser sanitizer', () => {
    const supported = 'setHTML' in Element.prototype;
    const result = internals().sanitizeToFragment('<b>x</b>');

    if (supported) {
      expect(result).toBeInstanceOf(DocumentFragment);
    } else {
      expect(result).toBeNull();
    }
  });
});
