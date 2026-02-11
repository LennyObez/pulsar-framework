/**
 * Before/after image comparison slider.
 *
 * Renders two images with a vertical dividing line that can be
 * dragged with pointer or keyboard to reveal more of either image.
 *
 * @example
 * ```html
 * <cms-image-compare
 *   data-before-src="before.jpg"
 *   data-before-alt="Before renovation"
 *   data-after-src="after.jpg"
 *   data-after-alt="After renovation"
 *   data-before-label="Before"
 *   data-after-label="After">
 * </cms-image-compare>
 * ```
 */
export class ImageCompare extends HTMLElement {
  private position = 50;
  private isDragging = false;
  private container: HTMLElement | null = null;
  private prefersReducedMotion = false;

  connectedCallback(): void {
    this.prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.render();
    this.setupInteraction();
  }

  disconnectedCallback(): void {
    // Listeners are on this element, cleaned up by GC.
  }

  private render(): void {
    const beforeSrc = this.dataset.beforeSrc ?? '';
    const beforeAlt = this.dataset.beforeAlt ?? 'Before';
    const afterSrc = this.dataset.afterSrc ?? '';
    const afterAlt = this.dataset.afterAlt ?? 'After';
    const beforeLabel = this.dataset.beforeLabel ?? 'Before';
    const afterLabel = this.dataset.afterLabel ?? 'After';

    // Build the component using safe DOM methods — no user data in innerHTML.
    // Static scaffold only; user-controlled values set via setAttribute/textContent below.
    this.buildStaticScaffold();

    // Set user-controlled values via DOM API (defense-in-depth, no interpolation)
    const afterImg = this.querySelector<HTMLImageElement>('.cms-compare__after');
    const beforeImg = this.querySelector<HTMLImageElement>('.cms-compare__before');

    if (afterImg) {
      afterImg.src = afterSrc;
      afterImg.alt = afterAlt;
    }

    if (beforeImg) {
      beforeImg.src = beforeSrc;
      beforeImg.alt = beforeAlt;
    }

    const beforeLabelEl = this.querySelector('.cms-compare__label--before');
    const afterLabelEl = this.querySelector('.cms-compare__label--after');

    if (beforeLabelEl) {
      beforeLabelEl.textContent = beforeLabel;
    }

    if (afterLabelEl) {
      afterLabelEl.textContent = afterLabel;
    }

    this.container = this.querySelector('.cms-compare__container');

    // Size the before image to match the after image dimensions
    if (afterImg && beforeImg) {
      afterImg.addEventListener('load', () => {
        beforeImg.style.width = `${afterImg.clientWidth}px`;
      });
    }
  }

  /**
   * Build the static HTML scaffold using only trusted, non-dynamic content.
   * All user-controlled data (src, alt, labels) is injected afterwards
   * via safe DOM methods (setAttribute / textContent).
   */
  private buildStaticScaffold(): void {
    const style = document.createElement('style');

    style.textContent = [
      'cms-image-compare { display: block; position: relative; overflow: hidden; cursor: col-resize; user-select: none; -webkit-user-select: none; }',
      '.cms-compare__container { position: relative; width: 100%; }',
      '.cms-compare__after { display: block; width: 100%; height: auto; }',
      '.cms-compare__before-wrapper { position: absolute; top: 0; left: 0; height: 100%; overflow: hidden; }',
      '.cms-compare__before { display: block; height: 100%; width: auto; max-width: none; }',
      '.cms-compare__divider { position: absolute; top: 0; height: 100%; width: 3px; background: #fff; box-shadow: 0 0 4px rgba(0,0,0,0.5); z-index: 2; }',
      '.cms-compare__handle { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 40px; height: 40px; background: #fff; border-radius: 50%; box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; font-size: 18px; color: #333; }',
      '.cms-compare__label { position: absolute; bottom: 12px; padding: 4px 12px; background: rgba(0,0,0,0.6); color: #fff; font-size: 12px; border-radius: 4px; z-index: 3; pointer-events: none; }',
      '.cms-compare__label--before { left: 12px; }',
      '.cms-compare__label--after { right: 12px; }',
      '@media (prefers-reduced-motion: reduce) { cms-image-compare, cms-image-compare * { transition: none !important; animation: none !important; } }',
    ].join('\n');
    this.appendChild(style);

    const container = document.createElement('div');

    container.className = 'cms-compare__container';
    container.setAttribute('role', 'slider');
    container.setAttribute('aria-valuemin', '0');
    container.setAttribute('aria-valuemax', '100');
    container.setAttribute('aria-valuenow', String(this.position));
    container.setAttribute('aria-label', 'Image comparison slider');
    container.setAttribute('tabindex', '0');

    const afterImg = document.createElement('img');

    afterImg.className = 'cms-compare__after';
    container.appendChild(afterImg);

    const beforeWrapper = document.createElement('div');

    beforeWrapper.className = 'cms-compare__before-wrapper';
    beforeWrapper.style.width = `${this.position}%`;

    const beforeImg = document.createElement('img');

    beforeImg.className = 'cms-compare__before';
    beforeWrapper.appendChild(beforeImg);
    container.appendChild(beforeWrapper);

    const divider = document.createElement('div');

    divider.className = 'cms-compare__divider';
    divider.style.left = `${this.position}%`;

    const handle = document.createElement('div');

    handle.className = 'cms-compare__handle';
    handle.textContent = '\u2194'; // ↔
    divider.appendChild(handle);
    container.appendChild(divider);

    const beforeLabel = document.createElement('span');

    beforeLabel.className = 'cms-compare__label cms-compare__label--before';
    container.appendChild(beforeLabel);

    const afterLabel = document.createElement('span');

    afterLabel.className = 'cms-compare__label cms-compare__label--after';
    container.appendChild(afterLabel);

    this.appendChild(container);
  }

  private setupInteraction(): void {
    if (!this.container) {
      return;
    }

    // Pointer events
    this.container.addEventListener('pointerdown', (e) => {
      this.isDragging = true;
      this.container?.setPointerCapture(e.pointerId);
      this.updatePosition(e.clientX);
    });

    this.container.addEventListener('pointermove', (e) => {
      if (this.isDragging) {
        this.updatePosition(e.clientX);
      }
    });

    this.container.addEventListener('pointerup', (e) => {
      this.isDragging = false;
      this.container?.releasePointerCapture(e.pointerId);
    });

    // Keyboard
    this.container.addEventListener('keydown', (e) => {
      switch (e.key) {
        case 'ArrowLeft':
          e.preventDefault();
          this.setPosition(this.position - 2);
          break;
        case 'ArrowRight':
          e.preventDefault();
          this.setPosition(this.position + 2);
          break;
      }
    });
  }

  private updatePosition(clientX: number): void {
    if (!this.container) {
      return;
    }

    const rect = this.container.getBoundingClientRect();
    const percentage = ((clientX - rect.left) / rect.width) * 100;

    this.setPosition(percentage);
  }

  private setPosition(percentage: number): void {
    this.position = Math.max(0, Math.min(100, percentage));

    const wrapper = this.querySelector<HTMLElement>('.cms-compare__before-wrapper');
    const divider = this.querySelector<HTMLElement>('.cms-compare__divider');
    const slider = this.container;

    if (wrapper) {
      wrapper.style.width = `${this.position}%`;
    }

    if (divider) {
      divider.style.left = `${this.position}%`;
    }

    if (slider) {
      slider.setAttribute('aria-valuenow', String(Math.round(this.position)));
    }
  }
}

customElements.define('cms-image-compare', ImageCompare);
