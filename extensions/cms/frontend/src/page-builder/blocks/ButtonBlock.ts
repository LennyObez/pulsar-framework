/**
 * Page builder button block.
 *
 * `<cms-pb-button>` renders an editable button with configurable text, URL,
 * and style variant (primary, secondary, outline).
 */

import { isValidUrl } from '../../utils/sanitizeHtml.js';

export class CmsPbButton extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-button');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private render(): void {
    this.innerHTML = '';

    const text = this.blockData['text'];
    const url = this.blockData['url'];
    const style = this.blockData['style'];

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-button__wrapper';

    const btn = document.createElement('a');
    btn.className = 'pb-block-button__link';

    // Apply style variant
    if (typeof style === 'string' && style !== '') {
      btn.classList.add(`pb-block-button__link--${style}`);
    } else {
      btn.classList.add('pb-block-button__link--primary');
    }

    const hrefValue = typeof url === 'string' ? url : '#';
    btn.href = isValidUrl(hrefValue) ? hrefValue : '#';
    btn.textContent = typeof text === 'string' ? text : 'Button';

    // Prevent navigation in editor; make text editable via click
    btn.addEventListener('click', (e) => {
      e.preventDefault();
    });

    // Double-click to edit text inline
    btn.addEventListener('dblclick', (e) => {
      e.preventDefault();
      const newText = prompt(
        'Button text:',
        typeof this.blockData['text'] === 'string' ? this.blockData['text'] : '',
      );
      if (newText !== null) {
        this.blockData['text'] = newText;
        this.render();
        this.emitUpdate();
      }
    });

    wrapper.appendChild(btn);
    this.appendChild(wrapper);
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

customElements.define('cms-pb-button', CmsPbButton);
