/**
 * Page builder counter block.
 *
 * `<cms-pb-counter>` renders an animated number counter display with
 * configurable value, prefix, suffix, and label.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

export class CmsPbCounter extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-counter');
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

    const value = this.blockData['value'];
    const prefix = this.blockData['prefix'];
    const suffix = this.blockData['suffix'];
    const label = this.blockData['label'];

    const numValue = typeof value === 'number' ? value : 0;
    const prefixStr = typeof prefix === 'string' ? prefix : '';
    const suffixStr = typeof suffix === 'string' ? suffix : '';
    const labelStr = typeof label === 'string' ? label : '';

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-counter__wrapper';

    // Counter display
    const display = document.createElement('div');
    display.className = 'pb-block-counter__display';

    if (prefixStr !== '') {
      const pre = document.createElement('span');
      pre.className = 'pb-block-counter__prefix';
      pre.textContent = prefixStr;
      display.appendChild(pre);
    }

    const numberEl = document.createElement('span');
    numberEl.className = 'pb-block-counter__number';
    numberEl.textContent = String(numValue);
    display.appendChild(numberEl);

    if (suffixStr !== '') {
      const suf = document.createElement('span');
      suf.className = 'pb-block-counter__suffix';
      suf.textContent = suffixStr;
      display.appendChild(suf);
    }

    wrapper.appendChild(display);

    if (labelStr !== '') {
      const labelEl = document.createElement('div');
      labelEl.className = 'pb-block-counter__label';
      labelEl.textContent = labelStr;
      wrapper.appendChild(labelEl);
    }

    // Edit controls
    const controls = document.createElement('div');
    controls.className = 'pb-block-counter__controls';

    controls.appendChild(
      this.createInput('Value', String(numValue), 'number', (v) => {
        this.blockData['value'] = Number(v) || 0;
        this.render();
        this.emitUpdate();
      }),
    );

    controls.appendChild(
      this.createInput('Prefix', prefixStr, 'text', (v) => {
        this.blockData['prefix'] = v;
        this.render();
        this.emitUpdate();
      }),
    );

    controls.appendChild(
      this.createInput('Suffix', suffixStr, 'text', (v) => {
        this.blockData['suffix'] = v;
        this.render();
        this.emitUpdate();
      }),
    );

    controls.appendChild(
      this.createInput('Label', labelStr, 'text', (v) => {
        this.blockData['label'] = v;
        this.render();
        this.emitUpdate();
      }),
    );

    wrapper.appendChild(controls);
    this.appendChild(wrapper);
  }

  private createInput(
    labelText: string,
    value: string,
    type: string,
    onChange: (value: string) => void,
  ): HTMLElement {
    const group = document.createElement('label');
    group.className = 'pb-block-counter__field';

    const span = document.createElement('span');
    span.className = 'pb-block-counter__field-label';
    span.textContent = escapeHtml(labelText);
    group.appendChild(span);

    const input = document.createElement('input');
    input.type = type;
    input.className = 'cms-input pb-block-counter__input';
    input.value = value;
    input.addEventListener('change', () => {
      onChange(input.value);
    });
    group.appendChild(input);

    return group;
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

customElements.define('cms-pb-counter', CmsPbCounter);
