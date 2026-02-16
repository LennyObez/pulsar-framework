/**
 * Page builder call-to-action block.
 *
 * `<cms-pb-cta>` renders a CTA section with heading, descriptive text,
 * and a configurable action button. Supports style variants.
 */

import { InlineEditor } from '../InlineEditor.js';
import { sanitizeHtml } from '../../utils/sanitizeHtml.js';

const CTA_VARIANTS = ['default', 'primary', 'dark', 'gradient'] as const;

export class CmsPbCta extends HTMLElement {
  private editor: InlineEditor | null = null;
  private headingEl: HTMLElement | null = null;
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-cta');
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
      this.blockData['heading'] = this.headingEl.innerHTML;
    }
    return { ...this.blockData };
  }

  private render(): void {
    this.editor?.destroy();
    this.innerHTML = '';

    const heading = this.blockData['heading'];
    const text = this.blockData['text'];
    const buttonText = this.blockData['buttonText'];
    const buttonUrl = this.blockData['buttonUrl'];
    const variant = this.blockData['variant'];

    const variantStr =
      typeof variant === 'string' && CTA_VARIANTS.includes(variant as (typeof CTA_VARIANTS)[number])
        ? variant
        : 'default';

    const section = document.createElement('div');
    section.className = `pb-block-cta__section pb-block-cta__section--${variantStr}`;

    // Editable heading
    const h2 = document.createElement('h2');
    h2.className = 'pb-block-cta__heading';
    // Sanitized to prevent XSS from stored data
    h2.innerHTML = typeof heading === 'string' ? sanitizeHtml(heading) : 'Your Call to Action';
    this.headingEl = h2;
    section.appendChild(h2);

    this.editor = new InlineEditor(h2, (html) => {
      this.blockData['heading'] = html;
      this.emitUpdate();
    });
    this.editor.enable();

    // Text input
    const textArea = document.createElement('textarea');
    textArea.className = 'cms-input pb-block-cta__text';
    textArea.rows = 2;
    textArea.value = typeof text === 'string' ? text : '';
    textArea.placeholder = 'Description text...';
    textArea.setAttribute('aria-label', 'CTA description');
    textArea.addEventListener('input', () => {
      this.blockData['text'] = textArea.value;
      this.emitUpdate();
    });
    section.appendChild(textArea);

    // Button config row
    const btnRow = document.createElement('div');
    btnRow.className = 'pb-block-cta__btn-row';

    const btnTextInput = document.createElement('input');
    btnTextInput.type = 'text';
    btnTextInput.className = 'cms-input';
    btnTextInput.value = typeof buttonText === 'string' ? buttonText : 'Get Started';
    btnTextInput.placeholder = 'Button text';
    btnTextInput.setAttribute('aria-label', 'Button text');
    btnTextInput.addEventListener('change', () => {
      this.blockData['buttonText'] = btnTextInput.value;
      this.emitUpdate();
    });
    btnRow.appendChild(btnTextInput);

    const btnUrlInput = document.createElement('input');
    btnUrlInput.type = 'url';
    btnUrlInput.className = 'cms-input';
    btnUrlInput.value = typeof buttonUrl === 'string' ? buttonUrl : '#';
    btnUrlInput.placeholder = 'Button URL';
    btnUrlInput.setAttribute('aria-label', 'Button URL');
    btnUrlInput.addEventListener('change', () => {
      this.blockData['buttonUrl'] = btnUrlInput.value;
      this.emitUpdate();
    });
    btnRow.appendChild(btnUrlInput);

    section.appendChild(btnRow);

    // Preview button
    const previewBtn = document.createElement('a');
    previewBtn.className = 'pb-block-cta__button';
    previewBtn.textContent = typeof buttonText === 'string' ? buttonText : 'Get Started';
    previewBtn.href = '#';
    previewBtn.addEventListener('click', (e) => e.preventDefault());
    section.appendChild(previewBtn);

    // Variant selector
    const controls = document.createElement('div');
    controls.className = 'pb-block-cta__controls';

    for (const v of CTA_VARIANTS) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pb-block-cta__variant-btn${v === variantStr ? ' pb-block-cta__variant-btn--active' : ''}`;
      btn.textContent = v.charAt(0).toUpperCase() + v.slice(1);
      btn.addEventListener('click', () => {
        this.blockData['variant'] = v;
        this.render();
        this.emitUpdate();
      });
      controls.appendChild(btn);
    }

    section.appendChild(controls);
    this.appendChild(section);
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

customElements.define('cms-pb-cta', CmsPbCta);
