/**
 * Page builder alert block.
 *
 * `<cms-pb-alert>` renders a styled alert box with four variants (info,
 * success, warning, error). The message is contentEditable for inline editing.
 */

import { InlineEditor } from '../InlineEditor.js';
import { sanitizeHtml } from '../../utils/sanitizeHtml.js';

const ALERT_TYPES = ['info', 'success', 'warning', 'error'] as const;

export class CmsPbAlert extends HTMLElement {
  private editor: InlineEditor | null = null;
  private messageEl: HTMLElement | null = null;
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-alert');
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
    if (this.messageEl) {
      this.blockData['message'] = this.messageEl.innerHTML;
    }
    return { ...this.blockData };
  }

  private render(): void {
    this.editor?.destroy();
    this.innerHTML = '';

    const type = this.blockData['type'];
    const message = this.blockData['message'];
    const dismissible = this.blockData['dismissible'];

    const alertType =
      typeof type === 'string' && ALERT_TYPES.includes(type as (typeof ALERT_TYPES)[number])
        ? type
        : 'info';

    const wrapper = document.createElement('div');
    wrapper.className = `pb-block-alert__box pb-block-alert__box--${alertType}`;
    wrapper.setAttribute('role', 'alert');

    // Type selector
    const controls = document.createElement('div');
    controls.className = 'pb-block-alert__controls';

    for (const t of ALERT_TYPES) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pb-block-alert__type-btn pb-block-alert__type-btn--${t}${t === alertType ? ' pb-block-alert__type-btn--active' : ''}`;
      btn.textContent = t.charAt(0).toUpperCase() + t.slice(1);
      btn.addEventListener('click', () => {
        this.blockData['type'] = t;
        this.render();
        this.emitUpdate();
      });
      controls.appendChild(btn);
    }

    wrapper.appendChild(controls);

    // Icon
    const iconMap: Record<string, string> = {
      info: '\u2139\uFE0F',
      success: '\u2705',
      warning: '\u26A0\uFE0F',
      error: '\u274C',
    };

    const icon = document.createElement('span');
    icon.className = 'pb-block-alert__icon';
    icon.textContent = iconMap[alertType] ?? '';
    icon.setAttribute('aria-hidden', 'true');
    wrapper.appendChild(icon);

    // Editable message
    const msgEl = document.createElement('div');
    msgEl.className = 'pb-block-alert__message';
    // Sanitized to prevent XSS from stored data
    msgEl.innerHTML = typeof message === 'string' ? sanitizeHtml(message) : 'Alert message...';
    this.messageEl = msgEl;
    wrapper.appendChild(msgEl);

    this.editor = new InlineEditor(msgEl, (html) => {
      this.blockData['message'] = html;
      this.emitUpdate();
    });
    this.editor.enable();

    // Dismissible toggle
    if (dismissible === true) {
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'pb-block-alert__close';
      closeBtn.textContent = '\u{2715}';
      closeBtn.title = 'Dismiss (preview only)';
      closeBtn.setAttribute('aria-label', 'Dismiss alert');
      wrapper.appendChild(closeBtn);
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

customElements.define('cms-pb-alert', CmsPbAlert);
