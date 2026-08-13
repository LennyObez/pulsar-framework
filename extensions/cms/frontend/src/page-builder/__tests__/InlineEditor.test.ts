/**
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest';
import { InlineEditor } from '../InlineEditor.js';

interface Internals {
  sanitize(html: string): string;
  sanitizeToFragment(html: string): DocumentFragment;
}

function editor(): Internals {
  const host = document.createElement('div');
  document.body.appendChild(host);

  return new InlineEditor(host, () => {}) as unknown as Internals;
}

describe('InlineEditor sanitizer', () => {
  it('keeps allowed inline formatting', () => {
    expect(editor().sanitize('<b>bold</b> and <em>emphasis</em>')).toBe(
      '<b>bold</b> and <em>emphasis</em>',
    );
  });

  it('reduces a disallowed element to its text', () => {
    expect(editor().sanitize('<div>plain</div>')).toBe('plain');
  });

  // Parsed as a document, a leading script belongs to head, and only body is
  // kept — so it is discarded rather than turned into visible text.
  it('discards a leading script entirely', () => {
    expect(editor().sanitize('<script>alert(1)</script>')).toBe('');
  });

  // Once text has opened body, a script parses there instead, where the
  // element rule replaces it with its own text. Inert, but no longer silent.
  it('never lets a script survive as markup once body is open', () => {
    const out = editor().sanitize('text<script>alert(1)</script>more');

    expect(out).not.toContain('<script');
    expect(out).toBe('textalert(1)more');
  });

  it('strips every attribute except href on an anchor', () => {
    const out = editor().sanitize('<b onclick="alert(1)" class="x">t</b>');

    expect(out).toBe('<b>t</b>');
  });

  it('keeps an absolute http and https href', () => {
    expect(editor().sanitize('<a href="https://example.com/a">l</a>')).toContain(
      'href="https://example.com/a"',
    );
  });

  it('keeps a rooted same-origin path', () => {
    expect(editor().sanitize('<a href="/docs/intro">l</a>')).toContain('href="/docs/intro"');
  });

  it('drops a javascript: href', () => {
    expect(editor().sanitize('<a href="javascript:alert(1)">l</a>')).not.toContain('javascript:');
  });

  // A leading slash alone does not mean same origin. Both of these resolve
  // off-site, and the rule that only tested for '/' admitted them.
  it('drops a protocol-relative href', () => {
    expect(editor().sanitize('<a href="//evil.example/x">l</a>')).not.toContain('evil.example');
  });

  it('drops a backslash protocol-relative href', () => {
    expect(editor().sanitize('<a href="/\\evil.example/x">l</a>')).not.toContain('evil.example');
  });

  it('drops a comment node', () => {
    expect(editor().sanitize('a<!-- note -->b')).toBe('ab');
  });

  // The paste path inserts nodes. If this ever returns a string again, the
  // caller is back to parsing untrusted markup in the live document.
  it('returns a DocumentFragment rather than markup', () => {
    const fragment = editor().sanitizeToFragment('<b>x</b>');

    expect(fragment).toBeInstanceOf(DocumentFragment);
    expect(fragment.firstChild?.nodeName).toBe('B');
  });

  it('carries no script element into the fragment', () => {
    const fragment = editor().sanitizeToFragment('<script>alert(1)</script><b>x</b>');
    const host = document.createElement('div');
    host.append(fragment);

    expect(host.querySelector('script')).toBeNull();
  });

  // Sanitizing an already sanitized string must be a no-op. When it is not,
  // serializing and re-parsing changes meaning, which is mutation XSS.
  it('is idempotent', () => {
    const subject = editor();
    const hostile =
      '<div><a href="javascript:alert(1)">l</a><script>alert(2)</script><b>k</b></div>';

    const once = subject.sanitize(hostile);

    expect(subject.sanitize(once)).toBe(once);
  });
});
