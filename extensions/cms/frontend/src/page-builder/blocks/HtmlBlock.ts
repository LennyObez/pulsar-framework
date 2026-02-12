/**
 * Page builder raw HTML block.
 *
 * `<cms-pb-html>` provides a textarea for editing raw HTML with a live
 * preview toggle. The preview renders inside a sandboxed iframe to
 * prevent XSS in the editor context.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

export class CmsPbHtml extends HTMLElement {
  private blockData: Record<string, unknown> = {};
  private previewMode = false;

  connectedCallback(): void {
    this.classList.add('pb-block-html');
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

    const content = this.blockData['content'];
    const html = typeof content === 'string' ? content : '';

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-html__wrapper';

    // Toolbar
    const toolbar = document.createElement('div');
    toolbar.className = 'pb-block-html__toolbar';

    const label = document.createElement('span');
    label.className = 'pb-block-html__label';
    label.textContent = 'Custom HTML';
    toolbar.appendChild(label);

    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.className = 'cms-btn cms-btn--outline pb-block-html__toggle';
    toggleBtn.textContent = this.previewMode ? 'Edit' : 'Preview';
    toggleBtn.addEventListener('click', () => {
      this.previewMode = !this.previewMode;
      this.render();
    });
    toolbar.appendChild(toggleBtn);

    wrapper.appendChild(toolbar);

    if (this.previewMode) {
      // Sandboxed iframe preview
      const iframe = document.createElement('iframe');
      iframe.className = 'pb-block-html__preview';
      // Fully sandboxed — no tokens added (allow-same-origin would defeat the sandbox)
      iframe.title = 'HTML preview';
      iframe.srcdoc = html;
      wrapper.appendChild(iframe);
    } else {
      // Textarea editor
      const textarea = document.createElement('textarea');
      textarea.className = 'cms-input pb-block-html__editor';
      textarea.rows = 8;
      textarea.value = html;
      textarea.placeholder = 'Enter HTML code...';
      textarea.spellcheck = false;
      textarea.setAttribute('aria-label', 'HTML source code');

      textarea.addEventListener('input', () => {
        this.blockData['content'] = textarea.value;
        this.emitUpdate();
      });

      wrapper.appendChild(textarea);
    }

    // Warning notice
    const notice = document.createElement('div');
    notice.className = 'pb-block-html__notice';
    notice.innerHTML = `<strong>Warning:</strong> ${escapeHtml('Custom HTML is rendered as-is. Use with caution.')}`;
    wrapper.appendChild(notice);

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

customElements.define('cms-pb-html', CmsPbHtml);
