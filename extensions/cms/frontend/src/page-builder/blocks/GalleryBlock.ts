/**
 * Page builder gallery block.
 *
 * `<cms-pb-gallery>` renders a grid of image thumbnails with add, remove,
 * and reorder capabilities. Column count is configurable.
 */

import { isValidUrl } from '../../utils/sanitizeHtml.js';

interface GalleryImage {
  src: string;
  alt: string;
  caption?: string;
}

export class CmsPbGallery extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-gallery');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getImages(): GalleryImage[] {
    const images = this.blockData['images'];
    if (!Array.isArray(images)) {
      return [];
    }
    return images.filter(
      (img): img is GalleryImage =>
        typeof img === 'object' && img !== null && typeof img.src === 'string',
    );
  }

  private getColumns(): number {
    const cols = this.blockData['columns'];
    if (typeof cols === 'number' && cols >= 1) {
      return cols;
    }
    return 3;
  }

  private render(): void {
    this.innerHTML = '';

    const images = this.getImages();
    const columns = this.getColumns();

    const grid = document.createElement('div');
    grid.className = 'pb-block-gallery__grid';
    grid.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;

    for (let i = 0; i < images.length; i++) {
      const image = images[i];
      if (!image) continue;
      const cell = document.createElement('div');
      cell.className = 'pb-block-gallery__cell';

      const img = document.createElement('img');
      img.className = 'pb-block-gallery__img';
      img.src = isValidUrl(image.src) ? image.src : '';
      img.alt = image.alt;
      img.loading = 'lazy';
      cell.appendChild(img);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'pb-block-gallery__remove';
      removeBtn.textContent = '\u{2715}';
      removeBtn.title = 'Remove image';
      removeBtn.setAttribute('aria-label', `Remove image ${i + 1}`);
      removeBtn.addEventListener('click', () => {
        this.removeImage(i);
      });
      cell.appendChild(removeBtn);

      grid.appendChild(cell);
    }

    this.appendChild(grid);

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--outline pb-block-gallery__add';
    addBtn.textContent = '+ Add Image';
    addBtn.addEventListener('click', () => {
      this.addImage();
    });
    this.appendChild(addBtn);
  }

  private addImage(): void {
    const url = prompt('Enter image URL:');
    if (!url || !isValidUrl(url.trim())) {
      return;
    }

    const alt = prompt('Enter alt text:') ?? '';
    const images = this.getImages();
    images.push({ src: url, alt });
    this.blockData['images'] = images;
    this.render();
    this.emitUpdate();
  }

  private removeImage(index: number): void {
    const images = this.getImages();
    images.splice(index, 1);
    this.blockData['images'] = images;
    this.render();
    this.emitUpdate();
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

customElements.define('cms-pb-gallery', CmsPbGallery);
