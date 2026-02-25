/**
 * Inline rich-text editor for text-based page builder blocks.
 *
 * Makes an element contentEditable and shows a floating formatting toolbar
 * on text selection. Supports bold, italic, link insertion, and clear formatting.
 * Sanitizes output to prevent XSS.
 */

const ALLOWED_TAGS = new Set(['B', 'STRONG', 'I', 'EM', 'A', 'BR', 'SPAN', 'U']);

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
  private readonly boundHideOnOutsideClick: (e: MouseEvent) => void;

  constructor(element: HTMLElement, onUpdate: (html: string) => void) {
    this.element = element;
    this.onUpdate = onUpdate;

    this.boundOnMouseUp = this.onSelectionChange.bind(this);
    this.boundOnKeyUp = this.onSelectionChange.bind(this);
    this.boundOnBlur = this.onBlur.bind(this);
    this.boundOnInput = this.onInput.bind(this);
    this.boundOnPaste = this.onPaste.bind(this);
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

    if (htmlData) {
      // Sanitize the pasted HTML through the same sanitizer
      const cleaned = this.sanitize(htmlData);
      const fragment = document.createRange().createContextualFragment(cleaned);
      range.insertNode(fragment);
    } else if (textData) {
      const textNode = document.createTextNode(textData);
      range.insertNode(textNode);
    }

    // Move cursor to end of inserted content
    selection.collapseToEnd();
    this.emitUpdate();
  }

  private onInput(): void {
    this.emitUpdate();
  }

  private emitUpdate(): void {
    const sanitized = this.sanitize(this.element.innerHTML);
    this.onUpdate(sanitized);
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
    document.execCommand(command, false);
    this.element.focus();
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

    document.execCommand('createLink', false, trimmed);
    this.element.focus();
  }

  private sanitize(html: string): string {
    const template = document.createElement('template');
    template.innerHTML = html;
    this.sanitizeNode(template.content);
    return template.innerHTML;
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
            if (
              !href.startsWith('http://') &&
              !href.startsWith('https://') &&
              !href.startsWith('/')
            ) {
              toRemove.push(child);
              continue;
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
