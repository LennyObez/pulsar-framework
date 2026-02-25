/**
 * Page builder progress bar block.
 *
 * `<cms-pb-progress>` renders a configurable progress bar with value,
 * max, label, and color options.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

export class CmsPbProgress extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-progress');
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
    const max = this.blockData['max'];
    const label = this.blockData['label'];
    const color = this.blockData['color'];

    const numValue = typeof value === 'number' ? value : 50;
    const numMax = typeof max === 'number' && max > 0 ? max : 100;
    const labelStr = typeof label === 'string' ? label : '';
    const colorStr = typeof color === 'string' ? color : '';
    const percentage = Math.min(100, Math.max(0, (numValue / numMax) * 100));

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-progress__wrapper';

    // Label and percentage
    if (labelStr !== '') {
      const labelRow = document.createElement('div');
      labelRow.className = 'pb-block-progress__header';

      const labelEl = document.createElement('span');
      labelEl.className = 'pb-block-progress__label';
      labelEl.textContent = labelStr;
      labelRow.appendChild(labelEl);

      const pctEl = document.createElement('span');
      pctEl.className = 'pb-block-progress__percentage';
      pctEl.textContent = `${Math.round(percentage)}%`;
      labelRow.appendChild(pctEl);

      wrapper.appendChild(labelRow);
    }

    // Progress bar
    const track = document.createElement('div');
    track.className = 'pb-block-progress__track';
    track.setAttribute('role', 'progressbar');
    track.setAttribute('aria-valuenow', String(numValue));
    track.setAttribute('aria-valuemin', '0');
    track.setAttribute('aria-valuemax', String(numMax));
    if (labelStr !== '') {
      track.setAttribute('aria-label', labelStr);
    }

    const fill = document.createElement('div');
    fill.className = 'pb-block-progress__fill';
    fill.style.width = `${percentage}%`;
    if (colorStr !== '') {
      fill.style.backgroundColor = colorStr;
    }

    track.appendChild(fill);
    wrapper.appendChild(track);

    // Controls
    const controls = document.createElement('div');
    controls.className = 'pb-block-progress__controls';

    controls.appendChild(
      this.createInput('Value', String(numValue), 'number', (v) => {
        this.blockData['value'] = Number(v) || 0;
        this.render();
        this.emitUpdate();
      }),
    );

    controls.appendChild(
      this.createInput('Max', String(numMax), 'number', (v) => {
        this.blockData['max'] = Number(v) || 100;
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
    group.className = 'pb-block-progress__field';

    const span = document.createElement('span');
    span.className = 'pb-block-progress__field-label';
    span.textContent = escapeHtml(labelText);
    group.appendChild(span);

    const input = document.createElement('input');
    input.type = type;
    input.className = 'cms-input';
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

customElements.define('cms-pb-progress', CmsPbProgress);
