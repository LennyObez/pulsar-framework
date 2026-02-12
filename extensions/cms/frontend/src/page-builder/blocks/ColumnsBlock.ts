/**
 * Page builder columns block.
 *
 * `<cms-pb-columns>` renders a configurable column layout (2, 3, or 4 columns).
 * Each column acts as a drop zone for child blocks in the page builder.
 */

export class CmsPbColumns extends HTMLElement {
  private blockData: Record<string, unknown> = {};
  private childBlocks: Array<{
    type: string;
    data: Record<string, unknown>;
  }> = [];

  connectedCallback(): void {
    this.classList.add('pb-block-columns');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  setChildren(
    children: Array<{
      type: string;
      data: Record<string, unknown>;
    }>,
  ): void {
    this.childBlocks = children;
    this.render();
  }

  private getColumnCount(): number {
    const count = this.blockData['columnCount'];
    if (typeof count === 'number' && count >= 2 && count <= 4) {
      return count;
    }
    return 2;
  }

  private render(): void {
    this.innerHTML = '';

    const columnCount = this.getColumnCount();

    const controls = document.createElement('div');
    controls.className = 'pb-block-columns__controls';

    for (const count of [2, 3, 4]) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pb-block-columns__count-btn${count === columnCount ? ' pb-block-columns__count-btn--active' : ''}`;
      btn.textContent = `${count} Columns`;
      btn.addEventListener('click', () => {
        this.blockData['columnCount'] = count;
        this.render();
        this.dispatchEvent(
          new CustomEvent('block-update', {
            bubbles: true,
            detail: this.getData(),
          }),
        );
      });
      controls.appendChild(btn);
    }

    this.appendChild(controls);

    const grid = document.createElement('div');
    grid.className = 'pb-block-columns__grid';
    grid.style.gridTemplateColumns = `repeat(${columnCount}, 1fr)`;

    for (let i = 0; i < columnCount; i++) {
      const column = document.createElement('div');
      column.className = 'pb-block-columns__column';
      column.dataset['columnIndex'] = String(i);

      // Render child blocks for this column
      const colChildren = this.childBlocks.filter(
        (child) =>
          (typeof child.data['_columnIndex'] === 'number' ? child.data['_columnIndex'] : 0) === i,
      );

      if (colChildren.length === 0) {
        const placeholder = document.createElement('div');
        placeholder.className = 'pb-block-columns__placeholder';
        placeholder.textContent = `Column ${i + 1} — drag blocks here`;
        column.appendChild(placeholder);
      } else {
        // Currently renders child blocks as type labels only.
        // Future iteration: render actual block elements for inline editing.
        for (const child of colChildren) {
          const childLabel = document.createElement('div');
          childLabel.className = 'pb-block-columns__child-label';
          childLabel.textContent = child.type;
          column.appendChild(childLabel);
        }
      }

      grid.appendChild(column);
    }

    this.appendChild(grid);
  }
}

customElements.define('cms-pb-columns', CmsPbColumns);
