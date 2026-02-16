/**
 * Page builder quote block.
 *
 * `<cms-pb-quote>` renders a blockquote with InlineEditor for the quote
 * text and a plain input for the citation/attribution.
 */

import { InlineEditor } from '../InlineEditor.js';
import { sanitizeHtml } from '../../utils/sanitizeHtml.js';

export class CmsPbQuote extends HTMLElement {
  private editor: InlineEditor | null = null;
  private quoteEl: HTMLElement | null = null;
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-quote');
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
    if (this.quoteEl) {
      this.blockData['text'] = this.quoteEl.innerHTML;
    }
    return { ...this.blockData };
  }

  private render(): void {
    this.editor?.destroy();
    this.innerHTML = '';

    const text = this.blockData['text'];
    const citation = this.blockData['citation'];

    const blockquote = document.createElement('blockquote');
    blockquote.className = 'pb-block-quote__blockquote';

    const quoteText = document.createElement('div');
    quoteText.className = 'pb-block-quote__text';
    // Sanitized to prevent XSS from stored data
    quoteText.innerHTML = typeof text === 'string' ? sanitizeHtml(text) : '';
    this.quoteEl = quoteText;
    blockquote.appendChild(quoteText);

    this.editor = new InlineEditor(quoteText, (html) => {
      this.blockData['text'] = html;
      this.emitUpdate();
    });
    this.editor.enable();

    // Citation input
    const footer = document.createElement('footer');
    footer.className = 'pb-block-quote__footer';

    const cite = document.createElement('input');
    cite.type = 'text';
    cite.className = 'cms-input pb-block-quote__citation';
    cite.placeholder = 'Attribution (optional)';
    cite.value = typeof citation === 'string' ? citation : '';
    cite.setAttribute('aria-label', 'Quote attribution');
    cite.addEventListener('change', () => {
      this.blockData['citation'] = cite.value;
      this.emitUpdate();
    });

    footer.appendChild(cite);
    blockquote.appendChild(footer);

    this.appendChild(blockquote);
  }

  private emitUpdate(): void {
    this.dispatchEvent(
      new CustomEvent('block-update', {
        bubbles: true,
        detail: this.getData(),
      }),
    );
  }
}

customElements.define('cms-pb-quote', CmsPbQuote);
