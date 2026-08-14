/**
 * Inline rich-text editor for text-based page builder blocks.
 *
 * Makes an element contentEditable and shows a floating formatting toolbar
 * on text selection. Supports bold, italic, link insertion, and clear formatting.
 * Sanitizes output to prevent XSS.
 */

const ALLOWED_TAGS = new Set([
  'B',
  'STRONG',
  'I',
  'EM',
  'A',
  'BR',
  'SPAN',
  'U',
  'S',
  'DEL',
  'CODE',
]);

// Handed to Element.setHTML(), which sanitizes in the browser's own parser. It
// enforces a floor of its own — script, iframe, object and every event handler
// attribute go regardless of what is listed here — and this narrows the result
// to what the editor is allowed to contain. href survives to be scheme-checked
// by sanitizeNode, which a Sanitizer config cannot express.
// lib.dom declares Sanitizer and SanitizerConfig but not the method that takes
// them, so the safe half of the API is unreachable from TypeScript. Declared
// here rather than reached through a cast: every call is guarded by the runtime
// check in sanitizeToFragment, so this describes a method we only ever invoke
// where it exists. Remove once lib.dom catches up.
declare global {
  interface Element {
    setHTML(html: string, options?: { sanitizer?: Sanitizer | SanitizerConfig }): void;
  }
}

const SANITIZER: SanitizerConfig = {
  elements: [...ALLOWED_TAGS].map((tag) => tag.toLowerCase()),
  attributes: ['href'],
};

export class InlineEditor {
  private readonly element: HTMLElement;
  private readonly onUpdate: (html: string) => void;
  private toolbar: HTMLElement | null = null;
  private active = false;

  private readonly boundOnMouseUp: () => void;
  private readonly boundOnKeyUp: () => void;
  private readonly boundOnBlur: () => void;
  private readonly boundOnInput: () => void;
  private readonly boundOnPaste: (e: ClipboardEvent) => void;
  private readonly boundOnKeydown: (e: KeyboardEvent) => void;
  private readonly boundHideOnOutsideClick: (e: MouseEvent) => void;

  constructor(element: HTMLElement, onUpdate: (html: string) => void) {
    this.element = element;
    this.onUpdate = onUpdate;

    this.boundOnMouseUp = this.onSelectionChange.bind(this);
    this.boundOnKeyUp = this.onSelectionChange.bind(this);
    this.boundOnBlur = this.onBlur.bind(this);
    this.boundOnInput = this.onInput.bind(this);
    this.boundOnPaste = this.onPaste.bind(this);
    this.boundOnKeydown = this.onKeydown.bind(this);
    this.boundHideOnOutsideClick = this.hideOnOutsideClick.bind(this);
  }

  enable(): void {
    if (this.active) {
      return;
    }

    this.active = true;
    this.element.contentEditable = 'true';
    this.element.classList.add('pb-inline-editable');
    this.element.addEventListener('mouseup', this.boundOnMouseUp);
    this.element.addEventListener('keyup', this.boundOnKeyUp);
    this.element.addEventListener('blur', this.boundOnBlur);
    this.element.addEventListener('input', this.boundOnInput);
    this.element.addEventListener('paste', this.boundOnPaste);
    this.element.addEventListener('keydown', this.boundOnKeydown);
  }

  disable(): void {
    if (!this.active) {
      return;
    }

    this.active = false;
    this.element.contentEditable = 'false';
    this.element.classList.remove('pb-inline-editable');
    this.element.removeEventListener('mouseup', this.boundOnMouseUp);
    this.element.removeEventListener('keyup', this.boundOnKeyUp);
    this.element.removeEventListener('blur', this.boundOnBlur);
    this.element.removeEventListener('input', this.boundOnInput);
    this.element.removeEventListener('paste', this.boundOnPaste);
    this.element.removeEventListener('keydown', this.boundOnKeydown);
    this.hideToolbar();
  }

  destroy(): void {
    this.disable();
  }

  private onSelectionChange(): void {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed || !selection.rangeCount) {
      return;
    }

    const range = selection.getRangeAt(0);
    if (!this.element.contains(range.commonAncestorContainer)) {
      return;
    }

    this.showToolbar(range);
  }

  private onBlur(): void {
    // Delay to allow toolbar button clicks to register
    setTimeout(() => {
      if (this.toolbar && !this.toolbar.contains(document.activeElement)) {
        this.hideToolbar();
        this.emitUpdate();
      }
    }, 150);
  }

  private onPaste(e: ClipboardEvent): void {
    e.preventDefault();

    const clipboardData = e.clipboardData;
    if (!clipboardData) {
      return;
    }

    // Prefer HTML paste with sanitization; fall back to plain text
    const htmlData = clipboardData.getData('text/html');
    const textData = clipboardData.getData('text/plain');

    const selection = window.getSelection();
    if (!selection || !selection.rangeCount) {
      return;
    }

    const range = selection.getRangeAt(0);
    range.deleteContents();

    // Rich paste only where the browser can sanitize it itself. Everywhere else
    // the clipboard is taken as plain text: formatting is lost, nothing can be
    // injected, and no untrusted string is ever parsed by this code.
    const pasted = htmlData ? this.sanitizeToFragment(htmlData) : null;

    if (pasted) {
      range.insertNode(pasted);
    } else if (textData) {
      range.insertNode(document.createTextNode(textData));
    }

    // Move cursor to end of inserted content
    selection.collapseToEnd();
    this.emitUpdate();
  }

  private onKeydown(e: KeyboardEvent): void {
    const isCtrl = e.ctrlKey || e.metaKey;

    if (isCtrl) {
      switch (e.key.toLowerCase()) {
        case 'b':
          e.preventDefault();
          this.execFormat('bold');
          this.emitUpdate();
          break;
        case 'i':
          e.preventDefault();
          this.execFormat('italic');
          this.emitUpdate();
          break;
        case 'k':
          e.preventDefault();
          this.insertLink();
          this.emitUpdate();
          break;
      }
    }
  }

  private onInput(): void {
    this.emitUpdate();
  }

  private emitUpdate(): void {
    this.onUpdate(this.sanitize());
  }

  private showToolbar(range: Range): void {
    this.hideToolbar();

    const toolbar = document.createElement('div');
    toolbar.className = 'pb-inline-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', 'Text formatting');

    const buttons: Array<{
      label: string;
      title: string;
      action: () => void;
      className?: string;
    }> = [
      {
        label: 'B',
        title: 'Bold',
        action: () => this.execFormat('bold'),
        className: 'pb-inline-toolbar__btn--bold',
      },
      {
        label: 'I',
        title: 'Italic',
        action: () => this.execFormat('italic'),
        className: 'pb-inline-toolbar__btn--italic',
      },
      {
        label: '\u{1F517}',
        title: 'Insert link',
        action: () => this.insertLink(),
      },
      {
        label: 'S',
        title: 'Strikethrough',
        action: () => this.execFormat('strikethrough'),
        className: 'pb-inline-toolbar__btn--strikethrough',
      },
      {
        label: '<>',
        title: 'Inline code',
        action: () => this.execFormat('code'),
        className: 'pb-inline-toolbar__btn--code',
      },
      {
        label: '\u{2715}',
        title: 'Clear formatting',
        action: () => this.execFormat('removeFormat'),
      },
    ];

    for (const btnDef of buttons) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pb-inline-toolbar__btn ${btnDef.className ?? ''}`.trim();
      btn.textContent = btnDef.label;
      btn.title = btnDef.title;
      btn.setAttribute('aria-label', btnDef.title);

      btn.addEventListener('mousedown', (e) => {
        e.preventDefault(); // Prevent blur on element
      });

      btn.addEventListener('click', (e) => {
        e.preventDefault();
        btnDef.action();
        this.emitUpdate();
      });

      toolbar.appendChild(btn);
    }

    // Position above the selection
    const rect = range.getBoundingClientRect();
    toolbar.style.position = 'fixed';
    toolbar.style.left = `${rect.left + rect.width / 2}px`;
    toolbar.style.top = `${rect.top - 8}px`;
    toolbar.style.transform = 'translate(-50%, -100%)';

    document.body.appendChild(toolbar);
    this.toolbar = toolbar;

    document.addEventListener('mousedown', this.boundHideOnOutsideClick);
  }

  private hideToolbar(): void {
    if (this.toolbar) {
      this.toolbar.remove();
      this.toolbar = null;
    }

    document.removeEventListener('mousedown', this.boundHideOnOutsideClick);
  }

  private hideOnOutsideClick(e: MouseEvent): void {
    if (
      this.toolbar &&
      !this.toolbar.contains(e.target as Node) &&
      !this.element.contains(e.target as Node)
    ) {
      this.hideToolbar();
    }
  }

  private execFormat(command: string): void {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed || !selection.rangeCount) {
      return;
    }

    const range = selection.getRangeAt(0);

    if (command === 'removeFormat') {
      // Extract text content and replace the selection with a plain text node
      const text = range.toString();
      range.deleteContents();
      range.insertNode(document.createTextNode(text));
      selection.collapseToEnd();
    } else {
      const tagMap: Record<string, string> = {
        bold: 'strong',
        italic: 'em',
        strikethrough: 's',
        code: 'code',
      };
      const tagName = tagMap[command] ?? null;
      if (tagName) {
        this.wrapSelection(selection, range, tagName);
      }
    }

    this.element.focus();
  }

  private wrapSelection(selection: Selection, range: Range, tagName: string): void {
    // Check if already wrapped in this tag -- if so, unwrap
    let ancestor: Node | null = range.commonAncestorContainer;
    while (ancestor && ancestor !== this.element) {
      if (
        ancestor.nodeType === Node.ELEMENT_NODE &&
        (ancestor as Element).tagName === tagName.toUpperCase()
      ) {
        // Unwrap: replace the tag with its contents
        const parent = ancestor.parentNode;
        if (parent) {
          while (ancestor.firstChild) {
            parent.insertBefore(ancestor.firstChild, ancestor);
          }
          parent.removeChild(ancestor);
        }
        return;
      }
      ancestor = ancestor.parentNode;
    }

    // Wrap selection in the formatting element
    const wrapper = document.createElement(tagName);
    const contents = range.extractContents();
    wrapper.appendChild(contents);
    range.insertNode(wrapper);

    // Re-select the wrapped content
    selection.removeAllRanges();
    const newRange = document.createRange();
    newRange.selectNodeContents(wrapper);
    selection.addRange(newRange);
  }

  private insertLink(): void {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) {
      return;
    }

    const url = prompt('Enter URL:');
    if (url === null || url.trim() === '') {
      return;
    }

    // Basic URL validation
    const trimmed = url.trim();
    if (
      !trimmed.startsWith('http://') &&
      !trimmed.startsWith('https://') &&
      !trimmed.startsWith('/')
    ) {
      return;
    }

    const range = selection.getRangeAt(0);
    const anchor = document.createElement('a');
    anchor.href = trimmed;
    const contents = range.extractContents();
    anchor.appendChild(contents);
    range.insertNode(anchor);

    // Re-select the link content
    selection.removeAllRanges();
    const newRange = document.createRange();
    newRange.selectNodeContents(anchor);
    selection.addRange(newRange);

    this.element.focus();
  }

  // Element.setHTML() is the only way this file turns an untrusted string into
  // nodes. The browser parses and sanitizes it in one step, so no partly-cleaned
  // tree ever exists here and nothing is serialized and parsed again — the round
  // trip mutation XSS lives in. Returns null where the API is missing, and the
  // caller falls back to plain text rather than to a hand-rolled parser.
  private sanitizeToFragment(html: string): DocumentFragment | null {
    if (!('setHTML' in Element.prototype)) {
      return null;
    }

    const holder = document.createElement('div');
    holder.setHTML(html, { sanitizer: SANITIZER });
    this.sanitizeNode(holder);

    const fragment = document.createDocumentFragment();
    fragment.append(...Array.from(holder.childNodes));

    return fragment;
  }

  // Reads the editor's own subtree, so there is nothing to parse: the nodes
  // already exist. Sanitizing a clone keeps the caret and the live selection
  // untouched while still refusing to emit anything outside the allowlist.
  private sanitize(): string {
    const clone = this.element.cloneNode(true) as HTMLElement;
    this.sanitizeNode(clone);

    return clone.innerHTML;
  }

  private sanitizeNode(node: Node): void {
    const toRemove: Node[] = [];

    for (const child of node.childNodes) {
      if (child.nodeType === Node.ELEMENT_NODE) {
        const el = child as Element;

        if (!ALLOWED_TAGS.has(el.tagName)) {
          // Replace disallowed element with its text content
          const text = document.createTextNode(el.textContent ?? '');
          node.replaceChild(text, child);
          continue;
        }

        // Remove all attributes except href on anchors
        const attrs = [...el.attributes];
        for (const attr of attrs) {
          if (el.tagName === 'A' && attr.name === 'href') {
            // Validate href
            const href = attr.value;
            // A leading / alone does not mean same origin: //host and /\host are
            // both protocol-relative and resolve off-site.
            const isRootedPath =
              href.startsWith('/') && !href.startsWith('//') && !href.startsWith('/\\');

            if (!href.startsWith('http://') && !href.startsWith('https://') && !isRootedPath) {
              toRemove.push(child);
              break;
            }
          } else {
            el.removeAttribute(attr.name);
          }
        }

        // Recurse into allowed elements
        this.sanitizeNode(child);
      } else if (
        child.nodeType !== Node.TEXT_NODE &&
        child.nodeType !== Node.DOCUMENT_FRAGMENT_NODE
      ) {
        toRemove.push(child);
      }
    }

    for (const node_ of toRemove) {
      node.removeChild(node_);
    }
  }
}
