/**
 * Page builder video block.
 *
 * `<cms-pb-video>` renders a responsive video embed for YouTube and Vimeo URLs.
 * Falls back to a native `<video>` element for direct video file URLs.
 */

import { isValidUrl } from '../../utils/sanitizeHtml.js';

export class CmsPbVideo extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-video');
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
    const caption = this.blockData['caption'];

    if (typeof src !== 'string' || src === '') {
      this.renderUrlInput();
      return;
    }

    const figure = document.createElement('figure');
    figure.className = 'pb-block-video__figure';

    const embedUrl = this.getEmbedUrl(src);

    if (embedUrl) {
      const container = document.createElement('div');
      container.className = 'pb-block-video__embed';

      const iframe = document.createElement('iframe');
      iframe.src = embedUrl;
      iframe.allowFullscreen = true;
      iframe.setAttribute('loading', 'lazy');
      iframe.setAttribute(
        'allow',
        'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
      );
      iframe.title = 'Embedded video';

      container.appendChild(iframe);
      figure.appendChild(container);
    } else if (isValidUrl(src)) {
      const video = document.createElement('video');
      video.className = 'pb-block-video__player';
      video.controls = true;
      video.src = src;

      const poster = this.blockData['poster'];
      if (typeof poster === 'string' && poster !== '') {
        video.poster = poster;
      }

      figure.appendChild(video);
    }

    if (typeof caption === 'string' && caption !== '') {
      const figcaption = document.createElement('figcaption');
      figcaption.className = 'pb-block-video__caption';
      figcaption.textContent = caption;
      figure.appendChild(figcaption);
    }

    this.appendChild(figure);

    // Add change URL button
    const changeBtn = document.createElement('button');
    changeBtn.type = 'button';
    changeBtn.className = 'cms-btn cms-btn--outline pb-block-video__change';
    changeBtn.textContent = 'Change URL';
    changeBtn.addEventListener('click', () => {
      const newUrl = prompt('Enter video URL:', src);
      if (newUrl !== null && isValidUrl(newUrl.trim())) {
        this.blockData['src'] = newUrl.trim();
        this.render();
        this.emitUpdate();
      }
    });
    this.appendChild(changeBtn);
  }

  private renderUrlInput(): void {
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-video__input-wrapper';

    const label = document.createElement('span');
    label.className = 'pb-block-video__input-label';
    label.textContent = 'Enter a YouTube, Vimeo, or video file URL:';
    wrapper.appendChild(label);

    const input = document.createElement('input');
    input.type = 'url';
    input.className = 'cms-input';
    input.placeholder = 'https://www.youtube.com/watch?v=...';
    wrapper.appendChild(input);

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--primary';
    addBtn.textContent = 'Embed';
    addBtn.addEventListener('click', () => {
      const trimmedValue = input.value.trim();
      if (trimmedValue !== '' && isValidUrl(trimmedValue)) {
        this.blockData['src'] = trimmedValue;
        this.render();
        this.emitUpdate();
      }
    });
    wrapper.appendChild(addBtn);

    this.appendChild(wrapper);
  }

  private getEmbedUrl(url: string): string | null {
    // YouTube
    const ytMatch = /(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/.exec(
      url,
    );
    if (ytMatch?.[1]) {
      return `https://www.youtube-nocookie.com/embed/${ytMatch[1]}`;
    }

    // Vimeo
    const vimeoMatch = /vimeo\.com\/(\d+)/.exec(url);
    if (vimeoMatch?.[1]) {
      return `https://player.vimeo.com/video/${vimeoMatch[1]}`;
    }

    return null;
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

customElements.define('cms-pb-video', CmsPbVideo);
