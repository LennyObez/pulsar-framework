/**
 * Page builder paragraph block.
 *
 * `<cms-pb-paragraph>` renders a contentEditable paragraph with inline
 * formatting support via the InlineEditor.
 */

import { InlineEditor } from '../InlineEditor.js';
import { sanitizeHtml } from '../../utils/sanitizeHtml.js';

export class CmsPbParagraph extends HTMLElement {
  private editor: InlineEditor | null = null;
  private contentEl: HTMLParagraphElement | null = null;
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-paragraph');
    this.render();
  }

  disconnectedCallback(): void {
    this.editor?.destroy();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    if (this.contentEl) {
      this.blockData['text'] = this.contentEl.innerHTML;
    }
    return { ...this.blockData };
  }

  private render(): void {
    this.editor?.destroy();
    this.innerHTML = '';

    const p = document.createElement('p');
    p.className = 'pb-block-paragraph__content';

    const alignment = this.blockData['alignment'];
    if (
      typeof alignment === 'string' &&
      ['left', 'center', 'right', 'justify'].includes(alignment)
    ) {
      p.style.textAlign = alignment;
    }

    const text = this.blockData['text'];
    // Sanitized to prevent XSS from stored data
    p.innerHTML = typeof text === 'string' ? sanitizeHtml(text) : '';
    this.contentEl = p;
    this.appendChild(p);

    this.editor = new InlineEditor(p, (html) => {
      this.blockData['text'] = html;
      this.dispatchEvent(
        new CustomEvent('block-update', {
          bubbles: true,
          detail: this.getData(),
        }),
      );
    });
    this.editor.enable();
  }
}

customElements.define('cms-pb-paragraph', CmsPbParagraph);
