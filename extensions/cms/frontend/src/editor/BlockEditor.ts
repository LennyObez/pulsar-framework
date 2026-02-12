/**
 * Block editor for the CMS admin interface.
 *
 * Manages an ordered list of content blocks with drag-and-drop reordering,
 * addition, removal, and serialization to JSON for persistence.
 */

export interface EditorBlock {
  readonly type: string;
  data: Record<string, unknown>;
}

export class BlockEditor {
  private blocks: EditorBlock[] = [];
  private readonly container: HTMLElement;
  private dragSourceIndex: number | null = null;

  constructor(container: HTMLElement, initialBlocks?: readonly EditorBlock[]) {
    this.container = container;

    if (initialBlocks) {
      this.blocks = initialBlocks.map((b) => ({ type: b.type, data: { ...b.data } }));
    }

    this.render();
  }

  addBlock(type: string, data: Record<string, unknown>, index?: number): void {
    const block: EditorBlock = { type, data: { ...data } };

    if (index !== undefined && index >= 0 && index <= this.blocks.length) {
      this.blocks.splice(index, 0, block);
    } else {
      this.blocks.push(block);
    }

    this.render();
  }

  removeBlock(index: number): void {
    if (index < 0 || index >= this.blocks.length) {
      return;
    }

    this.blocks.splice(index, 1);
    this.render();
  }

  moveBlock(from: number, to: number): void {
    if (
      from < 0 ||
      from >= this.blocks.length ||
      to < 0 ||
      to >= this.blocks.length ||
      from === to
    ) {
      return;
    }

    const [block] = this.blocks.splice(from, 1);
    if (block === undefined) {
      return;
    }

    this.blocks.splice(to, 0, block);
    this.render();
  }

  getBlocks(): readonly EditorBlock[] {
    return this.blocks.map((b) => ({ type: b.type, data: { ...b.data } }));
  }

  toJson(): string {
    return JSON.stringify(
      this.blocks.map((b, i) => ({
        type: b.type,
        sort_order: i,
        data: b.data,
      })),
    );
  }

  private render(): void {
    this.container.innerHTML = '';

    this.blocks.forEach((block, index) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'block-editor__block';
      wrapper.dataset['index'] = String(index);
      wrapper.draggable = true;

      // Drag-and-drop handlers
      wrapper.addEventListener('dragstart', (e: DragEvent) => {
        this.dragSourceIndex = index;
        wrapper.classList.add('block-editor__block--dragging');
        e.dataTransfer?.setData('text/plain', String(index));
      });

      wrapper.addEventListener('dragend', () => {
        wrapper.classList.remove('block-editor__block--dragging');
        this.dragSourceIndex = null;
      });

      wrapper.addEventListener('dragover', (e: DragEvent) => {
        e.preventDefault();
        wrapper.classList.add('block-editor__block--drag-over');
      });

      wrapper.addEventListener('dragleave', () => {
        wrapper.classList.remove('block-editor__block--drag-over');
      });

      wrapper.addEventListener('drop', (e: DragEvent) => {
        e.preventDefault();
        wrapper.classList.remove('block-editor__block--drag-over');

        if (this.dragSourceIndex !== null && this.dragSourceIndex !== index) {
          this.moveBlock(this.dragSourceIndex, index);
        }
      });

      // Block header with type label and remove button
      const header = document.createElement('div');
      header.className = 'block-editor__block-header';

      const typeLabel = document.createElement('span');
      typeLabel.className = 'block-editor__block-type';
      typeLabel.textContent = block.type;

      const removeBtn = document.createElement('button');
      removeBtn.className = 'block-editor__remove-btn';
      removeBtn.type = 'button';
      removeBtn.textContent = 'Remove';
      removeBtn.addEventListener('click', () => {
        this.removeBlock(index);
      });

      header.appendChild(typeLabel);
      header.appendChild(removeBtn);

      // Content area (contenteditable for text-based blocks)
      const content = document.createElement('div');
      content.className = 'block-editor__block-content';

      const textData = block.data['text'] ?? block.data['code'] ?? block.data['content'];
      if (typeof textData === 'string') {
        content.contentEditable = 'true';
        content.textContent = textData;

        content.addEventListener('input', () => {
          const key = 'text' in block.data ? 'text' : 'code' in block.data ? 'code' : 'content';
          block.data[key] = content.textContent ?? '';
        });
      } else {
        content.textContent = `[${block.type} block]`;
      }

      wrapper.appendChild(header);
      wrapper.appendChild(content);
      this.container.appendChild(wrapper);
    });
  }
}
