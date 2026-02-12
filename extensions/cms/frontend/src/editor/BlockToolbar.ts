/**
 * Floating toolbar for inserting new blocks into the block editor.
 *
 * Shows available block types as buttons. A "+" button between blocks
 * opens the toolbar for insertion at that position.
 */

import type { BlockEditor } from './BlockEditor.js';

export interface BlockTypeDefinition {
  readonly type: string;
  readonly label: string;
  readonly icon: string;
  readonly defaultData: Record<string, unknown>;
}

export class BlockToolbar {
  private readonly editor: BlockEditor;
  private readonly blockTypes: readonly BlockTypeDefinition[];
  private readonly container: HTMLElement;
  private toolbarEl: HTMLElement | null = null;
  private insertIndex: number | null = null;

  constructor(
    editor: BlockEditor,
    container: HTMLElement,
    blockTypes: readonly BlockTypeDefinition[],
  ) {
    this.editor = editor;
    this.container = container;
    this.blockTypes = blockTypes;

    this.renderInsertButtons();
  }

  private renderInsertButtons(): void {
    const blocks = this.editor.getBlocks();

    // Remove existing insert buttons
    this.container.querySelectorAll('.block-toolbar__insert-btn').forEach((el) => el.remove());

    // Add insert button before each block and after the last one
    const blockElements = this.container.querySelectorAll('.block-editor__block');
    const positions = blocks.length + 1;

    for (let i = 0; i < positions; i++) {
      const insertBtn = document.createElement('button');
      insertBtn.className = 'block-toolbar__insert-btn';
      insertBtn.type = 'button';
      insertBtn.textContent = '+';
      insertBtn.title = 'Insert block';

      insertBtn.addEventListener('click', () => {
        this.showToolbar(i, insertBtn);
      });

      const blockEl = blockElements[i];
      if (blockEl) {
        blockEl.parentNode?.insertBefore(insertBtn, blockEl);
      } else {
        this.container.appendChild(insertBtn);
      }
    }
  }

  private showToolbar(index: number, anchorEl: HTMLElement): void {
    this.hideToolbar();

    this.insertIndex = index;

    const toolbar = document.createElement('div');
    toolbar.className = 'block-toolbar__panel';

    this.blockTypes.forEach((blockType) => {
      const btn = document.createElement('button');
      btn.className = 'block-toolbar__type-btn';
      btn.type = 'button';
      btn.title = blockType.label;
      btn.innerHTML = `<span class="block-toolbar__icon">${this.escapeHtml(blockType.icon)}</span><span class="block-toolbar__label">${this.escapeHtml(blockType.label)}</span>`;

      btn.addEventListener('click', () => {
        if (this.insertIndex !== null) {
          this.editor.addBlock(blockType.type, { ...blockType.defaultData }, this.insertIndex);
        }

        this.hideToolbar();
        this.renderInsertButtons();
      });

      toolbar.appendChild(btn);
    });

    anchorEl.after(toolbar);
    this.toolbarEl = toolbar;

    // Close toolbar on outside click
    const closeHandler = (e: Event): void => {
      if (!toolbar.contains(e.target as Node) && e.target !== anchorEl) {
        this.hideToolbar();
        document.removeEventListener('click', closeHandler);
      }
    };

    // Defer to next tick to avoid closing immediately
    setTimeout(() => {
      document.addEventListener('click', closeHandler);
    }, 0);
  }

  private hideToolbar(): void {
    if (this.toolbarEl) {
      this.toolbarEl.remove();
      this.toolbarEl = null;
    }

    this.insertIndex = null;
  }

  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;

    return div.innerHTML;
  }
}
