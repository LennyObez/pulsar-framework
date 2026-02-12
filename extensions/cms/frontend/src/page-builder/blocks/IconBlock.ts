/**
 * Page builder icon block.
 *
 * `<cms-pb-icon>` renders a FontAwesome icon with configurable icon class,
 * size, and color.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

const ICON_SIZES = ['sm', 'md', 'lg', 'xl', '2xl'] as const;

export class CmsPbIcon extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-icon');
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

    const icon = this.blockData['icon'];
    const size = this.blockData['size'];
    const color = this.blockData['color'];

    const iconStr = typeof icon === 'string' ? icon : 'fa-star';
    const sizeStr =
      typeof size === 'string' && ICON_SIZES.includes(size as (typeof ICON_SIZES)[number])
        ? size
        : 'lg';
    const colorStr = typeof color === 'string' ? color : '';

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-icon__wrapper';

    // Icon preview
    const preview = document.createElement('div');
    preview.className = 'pb-block-icon__preview';

    const iconEl = document.createElement('i');
    iconEl.className = `fa ${escapeHtml(iconStr)} pb-block-icon__icon pb-block-icon__icon--${sizeStr}`;
    if (colorStr !== '') {
      iconEl.style.color = colorStr;
    }
    preview.appendChild(iconEl);
    wrapper.appendChild(preview);

    // Controls
    const controls = document.createElement('div');
    controls.className = 'pb-block-icon__controls';

    // Icon class input
    const iconGroup = document.createElement('label');
    iconGroup.className = 'pb-block-icon__field';
    const iconLabel = document.createElement('span');
    iconLabel.textContent = 'Icon class';
    iconGroup.appendChild(iconLabel);
    const iconInput = document.createElement('input');
    iconInput.type = 'text';
    iconInput.className = 'cms-input';
    iconInput.value = iconStr;
    iconInput.placeholder = 'fa-star';
    iconInput.addEventListener('change', () => {
      this.blockData['icon'] = iconInput.value;
      this.render();
      this.emitUpdate();
    });
    iconGroup.appendChild(iconInput);
    controls.appendChild(iconGroup);

    // Size selector
    const sizeGroup = document.createElement('label');
    sizeGroup.className = 'pb-block-icon__field';
    const sizeLabel = document.createElement('span');
    sizeLabel.textContent = 'Size';
    sizeGroup.appendChild(sizeLabel);
    const sizeSelect = document.createElement('select');
    sizeSelect.className = 'cms-input';
    for (const s of ICON_SIZES) {
      const opt = document.createElement('option');
      opt.value = s;
      opt.textContent = s.toUpperCase();
      if (s === sizeStr) opt.selected = true;
      sizeSelect.appendChild(opt);
    }
    sizeSelect.addEventListener('change', () => {
      this.blockData['size'] = sizeSelect.value;
      this.render();
      this.emitUpdate();
    });
    sizeGroup.appendChild(sizeSelect);
    controls.appendChild(sizeGroup);

    // Color input
    const colorGroup = document.createElement('label');
    colorGroup.className = 'pb-block-icon__field';
    const colorLabel = document.createElement('span');
    colorLabel.textContent = 'Color';
    colorGroup.appendChild(colorLabel);
    const colorInput = document.createElement('input');
    colorInput.type = 'color';
    colorInput.className = 'cms-input';
    colorInput.value = colorStr || '#333333';
    colorInput.addEventListener('change', () => {
      this.blockData['color'] = colorInput.value;
      this.render();
      this.emitUpdate();
    });
    colorGroup.appendChild(colorInput);
    controls.appendChild(colorGroup);

    wrapper.appendChild(controls);
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

customElements.define('cms-pb-icon', CmsPbIcon);
