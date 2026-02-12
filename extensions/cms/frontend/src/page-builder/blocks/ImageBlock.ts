/**
 * Page builder image block.
 *
 * `<cms-pb-image>` shows an image preview with alignment controls.
 * Clicking the image placeholder opens a media picker (placeholder for Batch 21).
 */

import { isValidUrl } from '../../utils/sanitizeHtml.js';

export class CmsPbImage extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-image');
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

    const src = this.blockData['src'];
    const alt = this.blockData['alt'];
    const caption = this.blockData['caption'];
    const alignment = this.blockData['alignment'];

    const figure = document.createElement('figure');
    figure.className = 'pb-block-image__figure';

    if (typeof alignment === 'string' && ['left', 'center', 'right'].includes(alignment)) {
      figure.style.textAlign = alignment;
    }

    if (typeof src === 'string' && src !== '' && isValidUrl(src)) {
      const img = document.createElement('img');
      img.className = 'pb-block-image__img';
      img.src = src;
      img.alt = typeof alt === 'string' ? alt : '';
      img.loading = 'lazy';

      img.addEventListener('click', () => {
        this.openMediaPicker();
      });

      figure.appendChild(img);
    } else {
      const placeholder = document.createElement('div');
      placeholder.className = 'pb-block-image__placeholder';
      placeholder.textContent = 'Click to select an image';
      placeholder.setAttribute('role', 'button');
      placeholder.tabIndex = 0;

      placeholder.addEventListener('click', () => {
        this.openMediaPicker();
      });

      placeholder.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.openMediaPicker();
        }
      });

      figure.appendChild(placeholder);
    }

    if (typeof caption === 'string' && caption !== '') {
      const figcaption = document.createElement('figcaption');
      figcaption.className = 'pb-block-image__caption';
      figcaption.textContent = caption;
      figure.appendChild(figcaption);
    }

    this.appendChild(figure);
  }

  private openMediaPicker(): void {
    // Prompt-based fallback until the media picker component is built (Batch 21)
    const url = prompt('Enter image URL:', (this.blockData['src'] as string) ?? '');
    if (url === null || !isValidUrl(url.trim())) {
      return;
    }

    const alt = prompt('Enter alt text:', (this.blockData['alt'] as string) ?? '');

    this.blockData['src'] = url.trim();
    this.blockData['alt'] = alt ?? '';
    this.render();

    this.dispatchEvent(
      new CustomEvent('block-update', {
        bubbles: true,
        detail: this.getData(),
      }),
    );
  }
}

customElements.define('cms-pb-image', CmsPbImage);
