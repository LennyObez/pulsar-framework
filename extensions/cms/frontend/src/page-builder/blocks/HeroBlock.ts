/**
 * Page builder hero block.
 *
 * `<cms-pb-hero>` renders a full-width hero section with background image,
 * heading, subtext, and optional CTA button with overlay support.
 */

import { InlineEditor } from '../InlineEditor.js';
import { sanitizeHtml } from '../../utils/sanitizeHtml.js';

export class CmsPbHero extends HTMLElement {
  private editor: InlineEditor | null = null;
  private headingEl: HTMLElement | null = null;
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-hero');
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

    const backgroundImage = this.blockData['backgroundImage'];
    const heading = this.blockData['heading'];
    const subtext = this.blockData['subtext'];
    const buttonText = this.blockData['buttonText'];
    const buttonUrl = this.blockData['buttonUrl'];
    const overlay = this.blockData['overlay'];

    const section = document.createElement('div');
    section.className = 'pb-block-hero__section';

    if (typeof backgroundImage === 'string' && backgroundImage !== '') {
      section.style.backgroundImage = `url(${CSS.escape(backgroundImage)})`;
      section.classList.add('pb-block-hero__section--has-bg');
    }

    if (overlay === true) {
      const overlayEl = document.createElement('div');
      overlayEl.className = 'pb-block-hero__overlay';
      section.appendChild(overlayEl);
    }

    const content = document.createElement('div');
    content.className = 'pb-block-hero__content';

    // Editable heading
    const h1 = document.createElement('h1');
    h1.className = 'pb-block-hero__heading';
    h1.innerHTML = typeof heading === 'string' ? sanitizeHtml(heading) : 'Hero Title';
    this.headingEl = h1;
    content.appendChild(h1);

    this.editor = new InlineEditor(h1, (html) => {
      this.blockData['heading'] = html;
      this.emitUpdate();
    });
    this.editor.enable();

    // Subtext
    const subtextArea = document.createElement('textarea');
    subtextArea.className = 'cms-input pb-block-hero__subtext';
    subtextArea.rows = 2;
    subtextArea.value = typeof subtext === 'string' ? subtext : '';
    subtextArea.placeholder = 'Subtext...';
    subtextArea.setAttribute('aria-label', 'Hero subtext');
    subtextArea.addEventListener('input', () => {
      this.blockData['subtext'] = subtextArea.value;
      this.emitUpdate();
    });
    content.appendChild(subtextArea);

    // Button config
    if (typeof buttonText === 'string' && buttonText !== '') {
      const btn = document.createElement('a');
      btn.className = 'pb-block-hero__button';
      btn.textContent = buttonText;
      btn.href = '#';
      btn.addEventListener('click', (e) => e.preventDefault());
      content.appendChild(btn);
    }

    section.appendChild(content);

    // Settings panel
    const settings = document.createElement('div');
    settings.className = 'pb-block-hero__settings';

    // Background image URL
    const bgGroup = document.createElement('label');
    bgGroup.className = 'pb-block-hero__field';
    const bgLabel = document.createElement('span');
    bgLabel.textContent = 'Background image URL';
    bgGroup.appendChild(bgLabel);
    const bgInput = document.createElement('input');
    bgInput.type = 'url';
    bgInput.className = 'cms-input';
    bgInput.value = typeof backgroundImage === 'string' ? backgroundImage : '';
    bgInput.placeholder = 'https://...';
    bgInput.addEventListener('change', () => {
      this.blockData['backgroundImage'] = bgInput.value;
      this.render();
      this.emitUpdate();
    });
    bgGroup.appendChild(bgInput);
    settings.appendChild(bgGroup);

    // Button text
    const btnTextGroup = document.createElement('label');
    btnTextGroup.className = 'pb-block-hero__field';
    const btnTextLabel = document.createElement('span');
    btnTextLabel.textContent = 'Button text';
    btnTextGroup.appendChild(btnTextLabel);
    const btnTextInput = document.createElement('input');
    btnTextInput.type = 'text';
    btnTextInput.className = 'cms-input';
    btnTextInput.value = typeof buttonText === 'string' ? buttonText : '';
    btnTextInput.addEventListener('change', () => {
      this.blockData['buttonText'] = btnTextInput.value;
      this.render();
      this.emitUpdate();
    });
    btnTextGroup.appendChild(btnTextInput);
    settings.appendChild(btnTextGroup);

    // Button URL
    const btnUrlGroup = document.createElement('label');
    btnUrlGroup.className = 'pb-block-hero__field';
    const btnUrlLabel = document.createElement('span');
    btnUrlLabel.textContent = 'Button URL';
    btnUrlGroup.appendChild(btnUrlLabel);
    const btnUrlInput = document.createElement('input');
    btnUrlInput.type = 'url';
    btnUrlInput.className = 'cms-input';
    btnUrlInput.value = typeof buttonUrl === 'string' ? buttonUrl : '';
    btnUrlInput.addEventListener('change', () => {
      this.blockData['buttonUrl'] = btnUrlInput.value;
      this.emitUpdate();
    });
    btnUrlGroup.appendChild(btnUrlInput);
    settings.appendChild(btnUrlGroup);

    // Overlay toggle
    const overlayGroup = document.createElement('label');
    overlayGroup.className = 'pb-block-hero__field pb-block-hero__field--inline';
    const overlayCheckbox = document.createElement('input');
    overlayCheckbox.type = 'checkbox';
    overlayCheckbox.checked = overlay === true;
    overlayCheckbox.addEventListener('change', () => {
      this.blockData['overlay'] = overlayCheckbox.checked;
      this.render();
      this.emitUpdate();
    });
    overlayGroup.appendChild(overlayCheckbox);
    const overlayLabel = document.createElement('span');
    overlayLabel.textContent = 'Dark overlay';
    overlayGroup.appendChild(overlayLabel);
    settings.appendChild(overlayGroup);

    section.appendChild(settings);
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

customElements.define('cms-pb-hero', CmsPbHero);
