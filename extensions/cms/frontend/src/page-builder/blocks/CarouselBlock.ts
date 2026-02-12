/**
 * Page builder carousel block.
 *
 * `<cms-pb-carousel>` renders an image/content carousel with previous/next
 * controls. Supports auto-play with configurable interval.
 */

export class CmsPbCarousel extends HTMLElement {
  private blockData: Record<string, unknown> = {};
  private currentSlide = 0;
  private autoPlayTimer: ReturnType<typeof setInterval> | null = null;

  connectedCallback(): void {
    this.classList.add('pb-block-carousel');
    this.render();
  }

  disconnectedCallback(): void {
    this.stopAutoPlay();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.currentSlide = 0;
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getSlides(): Array<{ imageUrl?: string; content?: string }> {
    const slides = this.blockData['slides'];
    if (!Array.isArray(slides)) return [];
    return slides.filter(
      (s): s is { imageUrl?: string; content?: string } => typeof s === 'object' && s !== null,
    );
  }

  private render(): void {
    this.stopAutoPlay();
    this.innerHTML = '';

    const slides = this.getSlides();
    const autoPlay = this.blockData['autoPlay'] === true;
    const interval = this.blockData['interval'];
    const intervalMs = typeof interval === 'number' && interval >= 1000 ? interval : 5000;

    if (this.currentSlide >= slides.length) {
      this.currentSlide = Math.max(0, slides.length - 1);
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-carousel__wrapper';

    // Slide display
    const viewport = document.createElement('div');
    viewport.className = 'pb-block-carousel__viewport';
    viewport.setAttribute('role', 'group');
    viewport.setAttribute('aria-roledescription', 'slide');
    viewport.setAttribute(
      'aria-label',
      slides.length > 0 ? `Slide ${this.currentSlide + 1} of ${slides.length}` : 'No slides',
    );

    const currentSlideData = slides[this.currentSlide];
    if (slides.length > 0 && currentSlideData) {
      if (typeof currentSlideData.imageUrl === 'string' && currentSlideData.imageUrl !== '') {
        const img = document.createElement('img');
        img.className = 'pb-block-carousel__image';
        img.src = currentSlideData.imageUrl;
        img.alt = `Slide ${this.currentSlide + 1}`;
        img.loading = 'lazy';
        viewport.appendChild(img);
      }

      if (typeof currentSlideData.content === 'string' && currentSlideData.content !== '') {
        const text = document.createElement('div');
        text.className = 'pb-block-carousel__text';
        text.textContent = currentSlideData.content;
        viewport.appendChild(text);
      }

      if (
        (!currentSlideData.imageUrl || currentSlideData.imageUrl === '') &&
        (!currentSlideData.content || currentSlideData.content === '')
      ) {
        const empty = document.createElement('div');
        empty.className = 'pb-block-carousel__empty-slide';
        empty.textContent = `Slide ${this.currentSlide + 1} — click edit to add content`;
        viewport.appendChild(empty);
      }
    } else {
      const placeholder = document.createElement('div');
      placeholder.className = 'pb-block-carousel__placeholder';
      placeholder.textContent = 'Add slides to the carousel';
      viewport.appendChild(placeholder);
    }

    // Keyboard navigation and auto-play pause
    wrapper.tabIndex = 0;
    wrapper.setAttribute('aria-label', 'Image carousel');
    wrapper.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        this.stopAutoPlay();
        this.currentSlide = (this.currentSlide - 1 + slides.length) % slides.length;
        this.render();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        this.stopAutoPlay();
        this.currentSlide = (this.currentSlide + 1) % slides.length;
        this.render();
      }
    });
    wrapper.addEventListener('mouseenter', () => {
      this.stopAutoPlay();
    });

    wrapper.appendChild(viewport);

    // Navigation controls
    if (slides.length > 1) {
      const nav = document.createElement('div');
      nav.className = 'pb-block-carousel__nav';

      const prevBtn = document.createElement('button');
      prevBtn.type = 'button';
      prevBtn.className = 'pb-block-carousel__prev';
      prevBtn.textContent = '\u2039';
      prevBtn.title = 'Previous slide';
      prevBtn.setAttribute('aria-label', 'Previous slide');
      prevBtn.addEventListener('click', () => {
        this.stopAutoPlay();
        this.currentSlide = (this.currentSlide - 1 + slides.length) % slides.length;
        this.render();
      });
      nav.appendChild(prevBtn);

      // Dots
      const dots = document.createElement('div');
      dots.className = 'pb-block-carousel__dots';
      for (let i = 0; i < slides.length; i++) {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = `pb-block-carousel__dot${i === this.currentSlide ? ' pb-block-carousel__dot--active' : ''}`;
        dot.setAttribute('aria-label', `Go to slide ${i + 1}`);
        if (i === this.currentSlide) {
          dot.setAttribute('aria-current', 'page');
        }
        const idx = i;
        dot.addEventListener('click', () => {
          this.currentSlide = idx;
          this.render();
        });
        dots.appendChild(dot);
      }
      nav.appendChild(dots);

      const nextBtn = document.createElement('button');
      nextBtn.type = 'button';
      nextBtn.className = 'pb-block-carousel__next';
      nextBtn.textContent = '\u203A';
      nextBtn.title = 'Next slide';
      nextBtn.setAttribute('aria-label', 'Next slide');
      nextBtn.addEventListener('click', () => {
        this.stopAutoPlay();
        this.currentSlide = (this.currentSlide + 1) % slides.length;
        this.render();
      });
      nav.appendChild(nextBtn);

      wrapper.appendChild(nav);
    }

    // Slide editor
    const editor = document.createElement('div');
    editor.className = 'pb-block-carousel__editor';

    const slideInfo = document.createElement('span');
    slideInfo.className = 'pb-block-carousel__slide-info';
    slideInfo.textContent =
      slides.length > 0 ? `Slide ${this.currentSlide + 1} of ${slides.length}` : 'No slides';
    editor.appendChild(slideInfo);

    const editorSlide = slides[this.currentSlide];
    if (slides.length > 0 && editorSlide) {
      const imgInput = document.createElement('input');
      imgInput.type = 'url';
      imgInput.className = 'cms-input';
      imgInput.placeholder = 'Image URL';
      imgInput.value = typeof editorSlide.imageUrl === 'string' ? editorSlide.imageUrl : '';
      imgInput.setAttribute('aria-label', 'Slide image URL');
      const slideIdx = this.currentSlide;
      imgInput.addEventListener('change', () => {
        const current = this.getSlides();
        const existing = current[slideIdx];
        if (existing) {
          current[slideIdx] = { ...existing, imageUrl: imgInput.value };
          this.blockData['slides'] = current;
          this.render();
          this.emitUpdate();
        }
      });
      editor.appendChild(imgInput);

      const contentInput = document.createElement('input');
      contentInput.type = 'text';
      contentInput.className = 'cms-input';
      contentInput.placeholder = 'Slide text content';
      contentInput.value = typeof editorSlide.content === 'string' ? editorSlide.content : '';
      contentInput.setAttribute('aria-label', 'Slide text content');
      contentInput.addEventListener('change', () => {
        const current = this.getSlides();
        const existing = current[slideIdx];
        if (existing) {
          current[slideIdx] = { ...existing, content: contentInput.value };
          this.blockData['slides'] = current;
          this.render();
          this.emitUpdate();
        }
      });
      editor.appendChild(contentInput);

      // Remove slide
      if (slides.length > 1) {
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'cms-btn cms-btn--outline';
        removeBtn.textContent = 'Remove Slide';
        removeBtn.addEventListener('click', () => {
          const current = this.getSlides();
          current.splice(slideIdx, 1);
          this.blockData['slides'] = current;
          if (this.currentSlide >= current.length) {
            this.currentSlide = Math.max(0, current.length - 1);
          }
          this.render();
          this.emitUpdate();
        });
        editor.appendChild(removeBtn);
      }
    }

    // Add slide
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--outline';
    addBtn.textContent = '+ Add Slide';
    addBtn.addEventListener('click', () => {
      const current = this.getSlides();
      current.push({ imageUrl: '', content: '' });
      this.blockData['slides'] = current;
      this.currentSlide = current.length - 1;
      this.render();
      this.emitUpdate();
    });
    editor.appendChild(addBtn);

    // Autoplay toggle
    const autoPlayLabel = document.createElement('label');
    autoPlayLabel.className = 'pb-block-carousel__autoplay';
    const autoPlayCheck = document.createElement('input');
    autoPlayCheck.type = 'checkbox';
    autoPlayCheck.checked = autoPlay;
    autoPlayCheck.addEventListener('change', () => {
      this.blockData['autoPlay'] = autoPlayCheck.checked;
      this.render();
      this.emitUpdate();
    });
    autoPlayLabel.appendChild(autoPlayCheck);
    const autoPlaySpan = document.createElement('span');
    autoPlaySpan.textContent = 'Auto-play';
    autoPlayLabel.appendChild(autoPlaySpan);
    editor.appendChild(autoPlayLabel);

    wrapper.appendChild(editor);
    this.appendChild(wrapper);

    // Start autoplay if enabled
    if (autoPlay && slides.length > 1) {
      this.autoPlayTimer = setInterval(() => {
        this.currentSlide = (this.currentSlide + 1) % slides.length;
        this.render();
      }, intervalMs);
    }
  }

  private stopAutoPlay(): void {
    if (this.autoPlayTimer !== null) {
      clearInterval(this.autoPlayTimer);
      this.autoPlayTimer = null;
    }
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

customElements.define('cms-pb-carousel', CmsPbCarousel);
