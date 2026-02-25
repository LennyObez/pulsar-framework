/**
 * Page builder embed block.
 *
 * `<cms-pb-embed>` renders an oEmbed URL or a raw iframe embed. Users enter
 * a URL and the block attempts to render it as an embedded preview.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';
import { isValidUrl } from '../../utils/sanitizeHtml.js';

export class CmsPbEmbed extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-embed');
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

    const url = this.blockData['url'];
    const html = this.blockData['html'];

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-embed__wrapper';

    if (typeof url === 'string' && url !== '' && isValidUrl(url)) {
      // If we have pre-fetched embed HTML, render in sandboxed iframe
      if (typeof html === 'string' && html !== '') {
        const iframe = document.createElement('iframe');
        iframe.className = 'pb-block-embed__preview';
        iframe.sandbox.add('allow-same-origin');
        iframe.srcdoc = html;
        iframe.title = 'Embed preview';
        wrapper.appendChild(iframe);
      } else {
        // Fallback: render the URL as an iframe
        const container = document.createElement('div');
        container.className = 'pb-block-embed__iframe-wrapper';

        const iframe = document.createElement('iframe');
        iframe.className = 'pb-block-embed__iframe';
        iframe.src = url;
        iframe.title = 'Embedded content';
        iframe.setAttribute('loading', 'lazy');
        iframe.sandbox.add('allow-same-origin', 'allow-popups');
        container.appendChild(iframe);
        wrapper.appendChild(container);
      }

      // URL display and change button
      const footer = document.createElement('div');
      footer.className = 'pb-block-embed__footer';

      const urlDisplay = document.createElement('span');
      urlDisplay.className = 'pb-block-embed__url';
      urlDisplay.textContent = url;
      footer.appendChild(urlDisplay);

      const changeBtn = document.createElement('button');
      changeBtn.type = 'button';
      changeBtn.className = 'cms-btn cms-btn--outline';
      changeBtn.textContent = 'Change URL';
      changeBtn.addEventListener('click', () => {
        const newUrl = prompt('Enter embed URL:', url);
        if (newUrl !== null && newUrl.trim() !== '' && isValidUrl(newUrl.trim())) {
          this.blockData['url'] = newUrl.trim();
          this.blockData['html'] = '';
          this.render();
          this.emitUpdate();
        }
      });
      footer.appendChild(changeBtn);

      wrapper.appendChild(footer);
    } else {
      // URL input form
      const form = document.createElement('div');
      form.className = 'pb-block-embed__form';

      const label = document.createElement('span');
      label.className = 'pb-block-embed__input-label';
      label.textContent = 'Enter a URL to embed:';
      form.appendChild(label);

      const input = document.createElement('input');
      input.type = 'url';
      input.className = 'cms-input';
      input.placeholder = 'https://...';
      form.appendChild(input);

      const embedBtn = document.createElement('button');
      embedBtn.type = 'button';
      embedBtn.className = 'cms-btn cms-btn--primary';
      embedBtn.textContent = 'Embed';
      embedBtn.addEventListener('click', () => {
        if (input.value.trim() !== '' && isValidUrl(input.value.trim())) {
          this.blockData['url'] = input.value.trim();
          this.render();
          this.emitUpdate();
        }
      });
      form.appendChild(embedBtn);

      wrapper.appendChild(form);
    }

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

customElements.define('cms-pb-embed', CmsPbEmbed);
