/**
 * Page builder heading block.
 *
 * `<cms-pb-heading>` renders a contentEditable heading (h1-h6) with inline
 * formatting. The heading level is configurable via the inspector.
 */

import { InlineEditor } from '../InlineEditor.js';
import { sanitizeHtml } from '../../utils/sanitizeHtml.js';

export class CmsPbHeading extends HTMLElement {
  private editor: InlineEditor | null = null;
  private headingEl: HTMLElement | null = null;
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-heading');
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
    if (this.headingEl) {
      this.blockData['text'] = this.headingEl.innerHTML;
    }
    return { ...this.blockData };
  }

  private render(): void {
    this.editor?.destroy();
    this.innerHTML = '';

    const level = this.getLevel();
    const tag = `h${level}` as keyof HTMLElementTagNameMap;
    const heading = document.createElement(tag);
    heading.className = 'pb-block-heading__content';

    const text = this.blockData['text'];
    // Sanitized to prevent XSS from stored data
    heading.innerHTML = typeof text === 'string' ? sanitizeHtml(text) : '';

    this.headingEl = heading;
    this.appendChild(heading);

    this.editor = new InlineEditor(heading, (html) => {
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

  private getLevel(): number {
    const level = this.blockData['level'];
    if (typeof level === 'number' && level >= 1 && level <= 6) {
      return level;
    }
    return 2;
  }
}

customElements.define('cms-pb-heading', CmsPbHeading);
