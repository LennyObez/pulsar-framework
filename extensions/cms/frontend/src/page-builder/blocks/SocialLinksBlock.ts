/**
 * Page builder social links block.
 *
 * `<cms-pb-social-links>` renders a row of social media icon links with
 * add/remove/edit capabilities for each platform.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

interface SocialLink {
  platform: string;
  url: string;
}

const PLATFORMS = [
  'facebook',
  'twitter',
  'instagram',
  'linkedin',
  'youtube',
  'github',
  'tiktok',
  'pinterest',
  'mastodon',
  'discord',
  'reddit',
  'dribbble',
] as const;

const PLATFORM_ICONS: Record<string, string> = {
  facebook: 'fa-facebook',
  twitter: 'fa-twitter',
  instagram: 'fa-instagram',
  linkedin: 'fa-linkedin',
  youtube: 'fa-youtube',
  github: 'fa-github',
  tiktok: 'fa-tiktok',
  pinterest: 'fa-pinterest',
  mastodon: 'fa-mastodon',
  discord: 'fa-discord',
  reddit: 'fa-reddit',
  dribbble: 'fa-dribbble',
};

export class CmsPbSocialLinks extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-social-links');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getLinks(): SocialLink[] {
    const links = this.blockData['links'];
    if (!Array.isArray(links)) return [];
    return links.filter(
      (link): link is SocialLink =>
        typeof link === 'object' &&
        link !== null &&
        typeof link.platform === 'string' &&
        typeof link.url === 'string',
    );
  }

  private render(): void {
    this.innerHTML = '';

    const links = this.getLinks();

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-social-links__wrapper';

    // Preview row
    const preview = document.createElement('div');
    preview.className = 'pb-block-social-links__preview';

    for (const link of links) {
      const iconClass = PLATFORM_ICONS[link.platform] ?? 'fa-link';
      const a = document.createElement('a');
      a.className = 'pb-block-social-links__link';
      a.href = '#';
      a.title = link.platform;
      a.addEventListener('click', (e) => e.preventDefault());

      const icon = document.createElement('i');
      icon.className = `fa ${escapeHtml(iconClass)}`;
      a.appendChild(icon);
      preview.appendChild(a);
    }

    if (links.length === 0) {
      const placeholder = document.createElement('span');
      placeholder.className = 'pb-block-social-links__placeholder';
      placeholder.textContent = 'Add social media links below';
      preview.appendChild(placeholder);
    }

    wrapper.appendChild(preview);

    // Editor rows
    const editor = document.createElement('div');
    editor.className = 'pb-block-social-links__editor';

    for (let i = 0; i < links.length; i++) {
      const link = links[i];
      if (!link) continue;
      const row = document.createElement('div');
      row.className = 'pb-block-social-links__row';

      const select = document.createElement('select');
      select.className = 'cms-input';
      select.setAttribute('aria-label', `Platform ${i + 1}`);
      for (const p of PLATFORMS) {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p.charAt(0).toUpperCase() + p.slice(1);
        if (p === link.platform) opt.selected = true;
        select.appendChild(opt);
      }

      const index = i;
      select.addEventListener('change', () => {
        const current = this.getLinks();
        const existing = current[index];
        if (existing) {
          current[index] = { ...existing, platform: select.value };
          this.blockData['links'] = current;
          this.render();
          this.emitUpdate();
        }
      });
      row.appendChild(select);

      const urlInput = document.createElement('input');
      urlInput.type = 'url';
      urlInput.className = 'cms-input';
      urlInput.value = link.url;
      urlInput.placeholder = 'https://...';
      urlInput.setAttribute('aria-label', `URL for ${link.platform}`);
      urlInput.addEventListener('change', () => {
        const current = this.getLinks();
        const existing = current[index];
        if (existing) {
          current[index] = { ...existing, url: urlInput.value };
          this.blockData['links'] = current;
          this.emitUpdate();
        }
      });
      row.appendChild(urlInput);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'pb-block-social-links__remove';
      removeBtn.textContent = '\u{2715}';
      removeBtn.title = `Remove ${link.platform}`;
      removeBtn.setAttribute('aria-label', `Remove ${link.platform}`);
      removeBtn.addEventListener('click', () => {
        const current = this.getLinks();
        current.splice(index, 1);
        this.blockData['links'] = current;
        this.render();
        this.emitUpdate();
      });
      row.appendChild(removeBtn);

      editor.appendChild(row);
    }

    wrapper.appendChild(editor);

    // Add button
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--outline pb-block-social-links__add';
    addBtn.textContent = '+ Add Social Link';
    addBtn.addEventListener('click', () => {
      const current = this.getLinks();
      current.push({ platform: 'facebook', url: '' });
      this.blockData['links'] = current;
      this.render();
      this.emitUpdate();
    });
    wrapper.appendChild(addBtn);

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

customElements.define('cms-pb-social-links', CmsPbSocialLinks);
