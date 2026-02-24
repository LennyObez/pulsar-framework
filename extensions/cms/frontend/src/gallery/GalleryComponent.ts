/**
 * Masonry gallery component using CSS columns layout.
 *
 * Supports category filtering via data-category attributes on images
 * and opens a lightbox on click.
 *
 * @example
 * ```html
 * <cms-gallery>
 *   <img src="..." alt="..." data-category="nature" data-full-src="...">
 * </cms-gallery>
 * ```
 */
export class GalleryComponent extends HTMLElement {
  private currentFilter: string | null = null;
  private resizeObserver: ResizeObserver | null = null;

  connectedCallback(): void {
    this.setupLayout();
    this.setupFilterButtons();
    this.setupClickHandlers();
  }

  disconnectedCallback(): void {
    // Disconnect the ResizeObserver to prevent memory leaks
    this.resizeObserver?.disconnect();
    this.resizeObserver = null;
  }

  private setupLayout(): void {
    // Apply responsive column layout via CSS custom properties
    this.style.display = 'block';
    this.style.columnGap = '1rem';
    this.updateColumns();

    // Update columns on resize — store reference for cleanup in disconnectedCallback
    this.resizeObserver = new ResizeObserver(() => {
      this.updateColumns();
    });

    this.resizeObserver.observe(this);
  }

  private updateColumns(): void {
    const width = this.clientWidth;

    if (width < 640) {
      this.style.columnCount = '1';
    } else if (width < 1024) {
      this.style.columnCount = '2';
    } else {
      this.style.columnCount = this.dataset.columns ?? '3';
    }
  }

  private setupFilterButtons(): void {
    const filterBar = this.querySelector('[data-gallery-filters]');

    if (!filterBar) {
      return;
    }

    // ARIA roles for accessible tab-like filter navigation
    filterBar.setAttribute('role', 'tablist');
    const filterButtons = filterBar.querySelectorAll<HTMLElement>('[data-filter]');

    filterButtons.forEach((btn, index) => {
      btn.setAttribute('role', 'tab');
      btn.setAttribute('tabindex', index === 0 ? '0' : '-1');
      btn.setAttribute('aria-selected', btn.classList.contains('active') ? 'true' : 'false');
    });

    const activateFilter = (button: HTMLElement): void => {
      const filter = button.dataset.filter ?? null;

      this.filterByCategory(filter === 'all' ? null : filter);

      // Update active / aria state
      filterButtons.forEach((btn) => {
        btn.classList.remove('active');
        btn.setAttribute('aria-selected', 'false');
        btn.setAttribute('tabindex', '-1');
      });
      button.classList.add('active');
      button.setAttribute('aria-selected', 'true');
      button.setAttribute('tabindex', '0');
      button.focus();
    };

    filterBar.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLElement)) {
        return;
      }

      const button = target.closest('[data-filter]');

      if (!(button instanceof HTMLElement)) {
        return;
      }

      activateFilter(button);
    });

    // Keyboard navigation: Enter/Space to activate, Arrow keys to move
    filterBar.addEventListener('keydown', (event) => {
      if (!(event instanceof KeyboardEvent)) {
        return;
      }

      const target = event.target;

      if (!(target instanceof HTMLElement) || !target.matches('[data-filter]')) {
        return;
      }

      const buttons = Array.from(filterButtons);
      const currentIndex = buttons.indexOf(target);

      if (currentIndex === -1) {
        return;
      }

      switch (event.key) {
        case 'Enter':
        case ' ':
          event.preventDefault();
          activateFilter(target);
          break;
        case 'ArrowRight':
        case 'ArrowDown': {
          event.preventDefault();
          const nextIndex = (currentIndex + 1) % buttons.length;
          const nextBtn = buttons[nextIndex];

          if (nextBtn) {
            nextBtn.setAttribute('tabindex', '0');
            nextBtn.focus();
          }
          target.setAttribute('tabindex', '-1');
          break;
        }
        case 'ArrowLeft':
        case 'ArrowUp': {
          event.preventDefault();
          const prevIndex = (currentIndex - 1 + buttons.length) % buttons.length;
          const prevBtn = buttons[prevIndex];

          if (prevBtn) {
            prevBtn.setAttribute('tabindex', '0');
            prevBtn.focus();
          }
          target.setAttribute('tabindex', '-1');
          break;
        }
      }
    });
  }

  private setupClickHandlers(): void {
    this.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLElement)) {
        return;
      }

      const figure =
        target.closest('figure') ?? (target.tagName === 'IMG' ? target.parentElement : null);

      if (!figure) {
        return;
      }

      const img = figure.querySelector('img');

      if (!img) {
        return;
      }

      // Dispatch custom event for lightbox integration
      const fullSrc = img.dataset.fullSrc ?? img.src;

      const captionEl = figure.querySelector('figcaption');
      const detail: { src: string; alt: string; caption?: string; index: number } = {
        src: fullSrc,
        alt: img.alt,
        index: this.getVisibleImageIndex(img),
      };
      if (captionEl?.textContent != null) {
        detail.caption = captionEl.textContent;
      }

      this.dispatchEvent(
        new CustomEvent('gallery-item-click', {
          detail,
          bubbles: true,
        }),
      );
    });
  }

  private filterByCategory(category: string | null): void {
    this.currentFilter = category;
    const items = this.querySelectorAll('figure, [data-category]');

    items.forEach((item) => {
      if (!(item instanceof HTMLElement)) {
        return;
      }

      const itemCategory =
        item.dataset.category ?? item.querySelector('img')?.dataset.category ?? null;

      if (category === null || itemCategory === category) {
        item.style.display = '';
      } else {
        item.style.display = 'none';
      }
    });
  }

  private getVisibleImageIndex(img: HTMLImageElement): number {
    const images = Array.from(this.querySelectorAll('img'));
    const visible = images.filter((i) => {
      const parent = i.closest('figure') ?? i.parentElement;

      return !parent || parent.style.display !== 'none';
    });

    return visible.indexOf(img);
  }

  /**
   * Get all currently visible image sources for lightbox navigation.
   */
  getVisibleImages(): Array<{
    src: string;
    alt: string;
    caption?: string;
  }> {
    const images = Array.from(this.querySelectorAll('img'));

    return images
      .filter((img) => {
        const parent = img.closest('figure') ?? img.parentElement;

        return !parent || parent.style.display !== 'none';
      })
      .map((img) => {
        const captionText = img.closest('figure')?.querySelector('figcaption')?.textContent?.trim();
        const result: { src: string; alt: string; caption?: string } = {
          src: img.dataset.fullSrc ?? img.src,
          alt: img.alt,
        };
        if (captionText !== undefined && captionText !== null) {
          result.caption = captionText;
        }
        return result;
      });
  }
}

customElements.define('cms-gallery', GalleryComponent);
