/**
 * HTML sanitizer for page builder blocks.
 *
 * Strips dangerous elements (script, iframe, object, embed, form) and
 * dangerous attributes (event handlers, expression-based styles) from HTML
 * strings. Only allows a safe set of inline formatting tags and attributes.
 *
 * Security note: innerHTML is used intentionally on a detached <template>
 * element to parse the input for sanitization — the content is never
 * rendered to the live DOM until after dangerous nodes are removed.
 */

const ALLOWED_TAGS = new Set([
  'P',
  'BR',
  'B',
  'STRONG',
  'I',
  'EM',
  'U',
  'A',
  'SPAN',
  'SUB',
  'SUP',
  'MARK',
  'S',
  'CODE',
]);

const ALLOWED_ATTRS = new Set(['href', 'target', 'rel', 'class']);

export function sanitizeHtml(html: string): string {
  const template = document.createElement('template');
  template.innerHTML = html;

  const walker = document.createTreeWalker(template.content, NodeFilter.SHOW_ELEMENT);

  const toRemove: Element[] = [];

  while (walker.nextNode()) {
    const el = walker.currentNode as Element;

    if (!ALLOWED_TAGS.has(el.tagName)) {
      toRemove.push(el);
      continue;
    }

    for (const attr of Array.from(el.attributes)) {
      if (!ALLOWED_ATTRS.has(attr.name) || (attr.name === 'href' && !isValidUrl(attr.value))) {
        el.removeAttribute(attr.name);
      }
    }
  }

  for (const el of toRemove) {
    el.replaceWith(...Array.from(el.childNodes));
  }

  return template.innerHTML;
}

/**
 * Validate that a URL is safe to use in an href or src attribute.
 * Rejects javascript:, data:, vbscript: and other dangerous schemes.
 */
export function isValidUrl(url: string): boolean {
  const trimmed = url.trim();
  return (
    trimmed.startsWith('/') ||
    trimmed.startsWith('http://') ||
    trimmed.startsWith('https://') ||
    trimmed.startsWith('#') ||
    trimmed.startsWith('mailto:')
  );
}
