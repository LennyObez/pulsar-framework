/**
 * Page builder testimonial block.
 *
 * `<cms-pb-testimonial>` renders a testimonial with quote text, author
 * name, author title, and optional avatar image.
 */

import { InlineEditor } from '../InlineEditor.js';
import { sanitizeHtml } from '../../utils/sanitizeHtml.js';

export class CmsPbTestimonial extends HTMLElement {
  private editor: InlineEditor | null = null;
  private quoteEl: HTMLElement | null = null;
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-testimonial');
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
    if (this.quoteEl) {
      this.blockData['quote'] = this.quoteEl.innerHTML;
    }
    return { ...this.blockData };
  }

  private render(): void {
    this.editor?.destroy();
    this.innerHTML = '';

    const quote = this.blockData['quote'];
    const authorName = this.blockData['authorName'];
    const authorTitle = this.blockData['authorTitle'];
    const avatarUrl = this.blockData['avatarUrl'];

    const card = document.createElement('div');
    card.className = 'pb-block-testimonial__card';

    // Quote mark
    const quoteMark = document.createElement('div');
    quoteMark.className = 'pb-block-testimonial__quote-mark';
    quoteMark.textContent = '\u201C';
    quoteMark.setAttribute('aria-hidden', 'true');
    card.appendChild(quoteMark);

    // Editable quote
    const quoteEl = document.createElement('blockquote');
    quoteEl.className = 'pb-block-testimonial__quote';
    // Sanitized to prevent XSS from stored data
    quoteEl.innerHTML = typeof quote === 'string' ? sanitizeHtml(quote) : 'Enter testimonial...';
    this.quoteEl = quoteEl;
    card.appendChild(quoteEl);

    this.editor = new InlineEditor(quoteEl, (html) => {
      this.blockData['quote'] = html;
      this.emitUpdate();
    });
    this.editor.enable();

    // Author section
    const author = document.createElement('div');
    author.className = 'pb-block-testimonial__author';

    // Avatar
    if (typeof avatarUrl === 'string' && avatarUrl !== '') {
      const avatar = document.createElement('img');
      avatar.className = 'pb-block-testimonial__avatar';
      avatar.src = avatarUrl;
      avatar.alt = typeof authorName === 'string' ? authorName : '';
      avatar.loading = 'lazy';
      author.appendChild(avatar);
    } else {
      const avatarPlaceholder = document.createElement('div');
      avatarPlaceholder.className = 'pb-block-testimonial__avatar-placeholder';
      avatarPlaceholder.textContent = '\uD83D\uDC64';
      avatarPlaceholder.title = 'Click to set avatar URL';
      avatarPlaceholder.setAttribute('role', 'button');
      avatarPlaceholder.tabIndex = 0;
      avatarPlaceholder.addEventListener('click', () => {
        const url = prompt('Enter avatar image URL:');
        if (url) {
          this.blockData['avatarUrl'] = url;
          this.render();
          this.emitUpdate();
        }
      });
      author.appendChild(avatarPlaceholder);
    }

    const authorInfo = document.createElement('div');
    authorInfo.className = 'pb-block-testimonial__author-info';

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'cms-input pb-block-testimonial__name';
    nameInput.value = typeof authorName === 'string' ? authorName : '';
    nameInput.placeholder = 'Author name';
    nameInput.setAttribute('aria-label', 'Author name');
    nameInput.addEventListener('change', () => {
      this.blockData['authorName'] = nameInput.value;
      this.emitUpdate();
    });
    authorInfo.appendChild(nameInput);

    const titleInput = document.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'cms-input pb-block-testimonial__title';
    titleInput.value = typeof authorTitle === 'string' ? authorTitle : '';
    titleInput.placeholder = 'Title / Company (optional)';
    titleInput.setAttribute('aria-label', 'Author title');
    titleInput.addEventListener('change', () => {
      this.blockData['authorTitle'] = titleInput.value;
      this.emitUpdate();
    });
    authorInfo.appendChild(titleInput);

    author.appendChild(authorInfo);
    card.appendChild(author);
    this.appendChild(card);
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

customElements.define('cms-pb-testimonial', CmsPbTestimonial);
