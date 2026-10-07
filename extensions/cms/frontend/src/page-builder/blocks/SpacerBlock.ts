/**
 * Page builder spacer block.
 *
 * `<cms-pb-spacer>` renders a visual spacer with configurable height.
 * Drag the bottom handle to resize in the editor.
 */

export class CmsPbSpacer extends HTMLElement {
  private blockData: Record<string, unknown> = {};
  private dragging = false;
  private startY = 0;
  private startHeight = 0;

  connectedCallback(): void {
    this.classList.add('pb-block-spacer');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getHeight(): number {
    const h = this.blockData['height'];
    if (typeof h === 'number' && h > 0) {
      return h;
    }
    return 40;
  }

  private render(): void {
    this.innerHTML = '';

    const height = this.getHeight();

    const spacer = document.createElement('div');
    spacer.className = 'pb-block-spacer__area';
    spacer.style.height = `${height}px`;

    const label = document.createElement('span');
    label.className = 'pb-block-spacer__label';
    label.textContent = `${height}px`;
    spacer.appendChild(label);

    const handle = document.createElement('div');
    handle.className = 'pb-block-spacer__handle';
    handle.title = 'Drag to resize';

    handle.addEventListener('mousedown', (e) => {
      e.preventDefault();
      this.dragging = true;
      this.startY = e.clientY;
      this.startHeight = this.getHeight();

      const onMouseMove = (ev: MouseEvent): void => {
        if (!this.dragging) return;
        const delta = ev.clientY - this.startY;
        const newHeight = Math.max(8, this.startHeight + delta);
        this.blockData['height'] = newHeight;
        spacer.style.height = `${newHeight}px`;
        label.textContent = `${newHeight}px`;
      };

      const onMouseUp = (): void => {
        this.dragging = false;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
        this.emitUpdate();
      };

      document.addEventListener('mousemove', onMouseMove);
      document.addEventListener('mouseup', onMouseUp);
    });

    spacer.appendChild(handle);
    this.appendChild(spacer);
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

customElements.define('cms-pb-spacer', CmsPbSpacer);
