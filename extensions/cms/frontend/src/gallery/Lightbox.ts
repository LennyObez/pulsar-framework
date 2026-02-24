/**
 * Full-screen lightbox using native <dialog> element.
 *
 * Supports keyboard navigation, touch swipe, image preloading,
 * EXIF overlay toggle, and focus trapping.
 *
 * @example
 * ```html
 * <cms-lightbox></cms-lightbox>
 * ```
 */

interface LightboxImage {
  readonly src: string;
  readonly alt: string;
  readonly caption?: string;
}

export class Lightbox extends HTMLElement {
  private dialog: HTMLDialogElement | null = null;
  private images: LightboxImage[] = [];
  private currentIndex = 0;
  private touchStartX = 0;
  private touchStartY = 0;
  private previouslyFocused: Element | null = null;
  private prefersReducedMotion = false;

  connectedCallback(): void {
    this.prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.createDialog();
    this.setupGalleryListener();
  }

  disconnectedCallback(): void {
    this.dialog?.close();
  }

  private createDialog(): void {
    const dialog = document.createElement('dialog');

    dialog.classList.add('cms-lightbox');
    dialog.setAttribute('aria-label', 'Image lightbox');

    dialog.innerHTML = `
      <div class="cms-lightbox__backdrop"></div>
      <div class="cms-lightbox__container">
        <button class="cms-lightbox__close" aria-label="Close lightbox" type="button">&times;</button>
        <button class="cms-lightbox__prev" aria-label="Previous image" type="button">&#8249;</button>
        <div class="cms-lightbox__content">
          <img class="cms-lightbox__image" src="" alt="" loading="eager">
          <div class="cms-lightbox__caption" aria-live="polite"></div>
          <div class="cms-lightbox__exif" hidden></div>
        </div>
        <button class="cms-lightbox__next" aria-label="Next image" type="button">&#8250;</button>
        <button class="cms-lightbox__info" aria-label="Toggle image info" type="button">&#9432;</button>
        <div class="cms-lightbox__counter" aria-live="polite"></div>
      </div>
    `;

    // Inject scoped styles
    const style = document.createElement('style');

    style.textContent = `
      .cms-lightbox { border: none; padding: 0; max-width: 100vw; max-height: 100vh; width: 100vw; height: 100vh; background: transparent; }
      .cms-lightbox::backdrop { background: rgba(0,0,0,0.9); }
      .cms-lightbox__backdrop { position: absolute; inset: 0; }
      .cms-lightbox__container { position: relative; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
      .cms-lightbox__content { max-width: 90vw; max-height: 85vh; position: relative; text-align: center; }
      .cms-lightbox__image { max-width: 90vw; max-height: 80vh; object-fit: contain; }
      .cms-lightbox__close { position: absolute; top: 16px; right: 16px; font-size: 32px; background: none; border: none; color: #fff; cursor: pointer; z-index: 10; padding: 8px; }
      .cms-lightbox__prev, .cms-lightbox__next { position: absolute; top: 50%; transform: translateY(-50%); font-size: 48px; background: none; border: none; color: #fff; cursor: pointer; z-index: 10; padding: 16px; }
      .cms-lightbox__prev { left: 16px; }
      .cms-lightbox__next { right: 16px; }
      .cms-lightbox__caption { color: #fff; padding: 8px; font-size: 14px; }
      .cms-lightbox__counter { position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%); color: #ccc; font-size: 14px; }
      .cms-lightbox__info { position: absolute; top: 16px; left: 16px; font-size: 24px; background: none; border: none; color: #fff; cursor: pointer; z-index: 10; padding: 8px; }
      .cms-lightbox__exif { color: #ccc; font-size: 12px; padding: 8px; background: rgba(0,0,0,0.5); border-radius: 4px; margin-top: 8px; }
      @media (prefers-reduced-motion: reduce) {
        .cms-lightbox, .cms-lightbox * { transition: none !important; animation: none !important; }
      }
    `;

    dialog.prepend(style);
    this.appendChild(dialog);
    this.dialog = dialog;

    // Event listeners
    dialog.querySelector('.cms-lightbox__close')?.addEventListener('click', () => this.close());
    dialog.querySelector('.cms-lightbox__backdrop')?.addEventListener('click', () => this.close());
    dialog.querySelector('.cms-lightbox__prev')?.addEventListener('click', () => this.navigate(-1));
    dialog.querySelector('.cms-lightbox__next')?.addEventListener('click', () => this.navigate(1));
    dialog.querySelector('.cms-lightbox__info')?.addEventListener('click', () => this.toggleExif());

    dialog.addEventListener('keydown', (e) => this.onKeyDown(e));
    dialog.addEventListener('touchstart', (e) => this.onTouchStart(e), {
      passive: true,
    });
    dialog.addEventListener('touchend', (e) => this.onTouchEnd(e), {
      passive: true,
    });
  }

  private setupGalleryListener(): void {
    document.addEventListener('gallery-item-click', ((
      event: CustomEvent<{
        src: string;
        alt: string;
        caption?: string;
        index: number;
      }>,
    ) => {
      const gallery = event.target;

      if (
        gallery instanceof HTMLElement &&
        'getVisibleImages' in gallery &&
        typeof gallery.getVisibleImages === 'function'
      ) {
        this.images = gallery.getVisibleImages() as LightboxImage[];
      } else {
        this.images = [event.detail];
      }

      this.open(event.detail.index);
    }) as EventListener);
  }

  open(index: number): void {
    if (!this.dialog || this.images.length === 0) {
      return;
    }

    // Store the currently focused element so we can restore it on close
    this.previouslyFocused = document.activeElement;

    this.currentIndex = Math.max(0, Math.min(index, this.images.length - 1));
    this.updateDisplay();
    this.dialog.showModal();
    this.preloadAdjacent();
  }

  close(): void {
    this.dialog?.close();

    // Restore focus to the element that was focused before the lightbox opened
    if (this.previouslyFocused && this.previouslyFocused instanceof HTMLElement) {
      this.previouslyFocused.focus();
      this.previouslyFocused = null;
    }
  }

  private navigate(direction: number): void {
    const newIndex = this.currentIndex + direction;

    if (newIndex < 0 || newIndex >= this.images.length) {
      return;
    }

    this.currentIndex = newIndex;
    this.updateDisplay();
    this.preloadAdjacent();
  }

  private updateDisplay(): void {
    if (!this.dialog) {
      return;
    }

    const image = this.images[this.currentIndex];

    if (!image) {
      return;
    }

    const img = this.dialog.querySelector<HTMLImageElement>('.cms-lightbox__image');
    const caption = this.dialog.querySelector('.cms-lightbox__caption');
    const counter = this.dialog.querySelector('.cms-lightbox__counter');
    const prev = this.dialog.querySelector<HTMLButtonElement>('.cms-lightbox__prev');
    const next = this.dialog.querySelector<HTMLButtonElement>('.cms-lightbox__next');

    if (img) {
      img.src = image.src;
      img.alt = image.alt;
    }

    if (caption) {
      caption.textContent = image.caption ?? '';
    }

    if (counter) {
      counter.textContent = `${this.currentIndex + 1} / ${this.images.length}`;
    }

    if (prev) {
      prev.hidden = this.currentIndex === 0;
    }

    if (next) {
      next.hidden = this.currentIndex === this.images.length - 1;
    }

    // Hide EXIF when navigating
    const exif = this.dialog.querySelector<HTMLElement>('.cms-lightbox__exif');

    if (exif) {
      exif.hidden = true;
    }
  }

  private preloadAdjacent(): void {
    const indices = [this.currentIndex - 1, this.currentIndex + 1];

    for (const i of indices) {
      const adjacentImage = this.images[i];
      if (i >= 0 && i < this.images.length && adjacentImage) {
        const img = new Image();

        img.src = adjacentImage.src;
      }
    }
  }

  private toggleExif(): void {
    const exif = this.dialog?.querySelector<HTMLElement>('.cms-lightbox__exif');

    if (!exif) {
      return;
    }

    exif.hidden = !exif.hidden;

    if (!exif.hidden) {
      const image = this.images[this.currentIndex];

      exif.textContent = image ? `Source: ${image.src} | Alt: ${image.alt}` : '';
    }
  }

  private onKeyDown(event: KeyboardEvent): void {
    switch (event.key) {
      case 'ArrowLeft':
        event.preventDefault();
        this.navigate(-1);
        break;
      case 'ArrowRight':
        event.preventDefault();
        this.navigate(1);
        break;
      case 'Escape':
        event.preventDefault();
        this.close();
        break;
    }
  }

  private onTouchStart(event: TouchEvent): void {
    const touch = event.touches[0];

    if (touch) {
      this.touchStartX = touch.clientX;
      this.touchStartY = touch.clientY;
    }
  }

  private onTouchEnd(event: TouchEvent): void {
    const touch = event.changedTouches[0];

    if (!touch) {
      return;
    }

    const diffX = touch.clientX - this.touchStartX;
    const diffY = touch.clientY - this.touchStartY;

    // Only trigger swipe if horizontal movement is dominant
    if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50) {
      if (diffX > 0) {
        this.navigate(-1);
      } else {
        this.navigate(1);
      }
    }
  }
}

customElements.define('cms-lightbox', Lightbox);
