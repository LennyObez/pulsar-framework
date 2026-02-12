/**
 * Page builder separator block.
 *
 * `<cms-pb-separator>` renders a horizontal rule with configurable style
 * (solid, dashed, dotted, double) and optional color.
 */

export class CmsPbSeparator extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-separator');
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

    const style = this.blockData['style'];
    const color = this.blockData['color'];

    const validStyles = ['solid', 'dashed', 'dotted', 'double'];
    const borderStyle = typeof style === 'string' && validStyles.includes(style) ? style : 'solid';

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-separator__wrapper';

    const hr = document.createElement('hr');
    hr.className = 'pb-block-separator__line';
    hr.style.borderTopStyle = borderStyle;

    if (typeof color === 'string' && color !== '') {
      hr.style.borderTopColor = color;
    }

    wrapper.appendChild(hr);

    // Style selector
    const controls = document.createElement('div');
    controls.className = 'pb-block-separator__controls';

    for (const s of validStyles) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pb-block-separator__style-btn${s === borderStyle ? ' pb-block-separator__style-btn--active' : ''}`;
      btn.textContent = s.charAt(0).toUpperCase() + s.slice(1);
      btn.addEventListener('click', () => {
        this.blockData['style'] = s;
        this.render();
        this.emitUpdate();
      });
      controls.appendChild(btn);
    }

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

customElements.define('cms-pb-separator', CmsPbSeparator);
