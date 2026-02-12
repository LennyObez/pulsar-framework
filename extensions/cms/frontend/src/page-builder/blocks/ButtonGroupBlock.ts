/**
 * Page builder button group block.
 *
 * `<cms-pb-button-group>` renders multiple buttons in a row with
 * add/remove/edit capabilities for each button.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

interface ButtonItem {
  text: string;
  url: string;
  variant?: string;
}

const BUTTON_VARIANTS = ['primary', 'secondary', 'outline'] as const;

export class CmsPbButtonGroup extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-button-group');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getButtons(): ButtonItem[] {
    const buttons = this.blockData['buttons'];
    if (!Array.isArray(buttons)) return [];
    return buttons.filter(
      (btn): btn is ButtonItem =>
        typeof btn === 'object' &&
        btn !== null &&
        typeof btn.text === 'string' &&
        typeof btn.url === 'string',
    );
  }

  private render(): void {
    this.innerHTML = '';

    const buttons = this.getButtons();

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-button-group__wrapper';

    // Preview row
    const preview = document.createElement('div');
    preview.className = 'pb-block-button-group__preview';

    for (const btn of buttons) {
      const variant = btn.variant ?? 'primary';
      const a = document.createElement('a');
      a.className = `pb-block-button-group__btn pb-block-button-group__btn--${escapeHtml(variant)}`;
      a.textContent = btn.text;
      a.href = '#';
      a.addEventListener('click', (e) => e.preventDefault());
      preview.appendChild(a);
    }

    if (buttons.length === 0) {
      const placeholder = document.createElement('span');
      placeholder.className = 'pb-block-button-group__placeholder';
      placeholder.textContent = 'Add buttons below';
      preview.appendChild(placeholder);
    }

    wrapper.appendChild(preview);

    // Editor rows
    const editor = document.createElement('div');
    editor.className = 'pb-block-button-group__editor';

    for (let i = 0; i < buttons.length; i++) {
      const btn = buttons[i];
      if (!btn) continue;
      const row = document.createElement('div');
      row.className = 'pb-block-button-group__row';

      const textInput = document.createElement('input');
      textInput.type = 'text';
      textInput.className = 'cms-input';
      textInput.value = btn.text;
      textInput.placeholder = 'Button text';
      textInput.setAttribute('aria-label', `Button ${i + 1} text`);

      const idx = i;
      textInput.addEventListener('change', () => {
        const current = this.getButtons();
        const existing = current[idx];
        if (existing) {
          current[idx] = { ...existing, text: textInput.value };
          this.blockData['buttons'] = current;
          this.render();
          this.emitUpdate();
        }
      });
      row.appendChild(textInput);

      const urlInput = document.createElement('input');
      urlInput.type = 'url';
      urlInput.className = 'cms-input';
      urlInput.value = btn.url;
      urlInput.placeholder = 'URL';
      urlInput.setAttribute('aria-label', `Button ${i + 1} URL`);
      urlInput.addEventListener('change', () => {
        const current = this.getButtons();
        const existing = current[idx];
        if (existing) {
          current[idx] = { ...existing, url: urlInput.value };
          this.blockData['buttons'] = current;
          this.emitUpdate();
        }
      });
      row.appendChild(urlInput);

      const variantSelect = document.createElement('select');
      variantSelect.className = 'cms-input';
      variantSelect.setAttribute('aria-label', `Button ${i + 1} style`);
      for (const v of BUTTON_VARIANTS) {
        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = v.charAt(0).toUpperCase() + v.slice(1);
        if (v === (btn.variant ?? 'primary')) opt.selected = true;
        variantSelect.appendChild(opt);
      }
      variantSelect.addEventListener('change', () => {
        const current = this.getButtons();
        const existing = current[idx];
        if (existing) {
          current[idx] = { ...existing, variant: variantSelect.value };
          this.blockData['buttons'] = current;
          this.render();
          this.emitUpdate();
        }
      });
      row.appendChild(variantSelect);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'pb-block-button-group__remove';
      removeBtn.textContent = '\u{2715}';
      removeBtn.title = `Remove button ${i + 1}`;
      removeBtn.setAttribute('aria-label', `Remove button ${i + 1}`);
      removeBtn.addEventListener('click', () => {
        const current = this.getButtons();
        current.splice(idx, 1);
        this.blockData['buttons'] = current;
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
    addBtn.className = 'cms-btn cms-btn--outline pb-block-button-group__add';
    addBtn.textContent = '+ Add Button';
    addBtn.addEventListener('click', () => {
      const current = this.getButtons();
      current.push({ text: 'Button', url: '#', variant: 'primary' });
      this.blockData['buttons'] = current;
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

customElements.define('cms-pb-button-group', CmsPbButtonGroup);
