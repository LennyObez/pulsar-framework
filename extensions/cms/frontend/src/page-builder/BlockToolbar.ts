/**
 * Contextual block toolbar for the page builder.
 *
 * Renders a floating toolbar above the currently selected block with
 * common actions (move, duplicate, delete, transform) and block-specific
 * actions. Fully keyboard accessible.
 */

import type { BlockData } from './PageBuilderStore.js';

export interface BlockToolbarAction {
  label: string;
  icon: string;
  title: string;
  action: () => void;
  disabled?: boolean;
}

export interface BlockToolbarOptions {
  onMoveUp: (blockId: string) => void;
  onMoveDown: (blockId: string) => void;
  onDuplicate: (blockId: string) => void;
  onDelete: (blockId: string) => void;
  onTransform: (blockId: string, rect: DOMRect) => void;
  isFirst: (blockId: string) => boolean;
  isLast: (blockId: string) => boolean;
}

export class BlockToolbar {
  private readonly options: BlockToolbarOptions;
  private toolbarEl: HTMLElement | null = null;
  private currentBlockId: string | null = null;
  private positionObserver: ResizeObserver | null = null;

  constructor(options: BlockToolbarOptions) {
    this.options = options;
  }

  /** Show the toolbar above the given block element. */
  show(block: BlockData, blockEl: HTMLElement): void {
    this.hide();
    this.currentBlockId = block.id;

    const toolbar = document.createElement('div');
    toolbar.className = 'pb-block-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', `${this.formatType(block.type)} block actions`);
    toolbar.setAttribute('aria-orientation', 'horizontal');

    const actions = this.buildActions(block);
    const buttons: HTMLButtonElement[] = [];

    for (const action of actions) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pb-block-toolbar__btn';
      btn.title = action.title;
      btn.setAttribute('aria-label', action.title);
      btn.textContent = action.icon;
      btn.disabled = action.disabled ?? false;

      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        action.action();
      });

      btn.addEventListener('mousedown', (e) => {
        e.preventDefault(); // Prevent focus steal
      });

      toolbar.appendChild(btn);
      buttons.push(btn);
    }

    // Keyboard navigation within toolbar
    toolbar.addEventListener('keydown', (e) => {
      const enabledButtons = buttons.filter((b) => !b.disabled);
      const currentIndex = enabledButtons.indexOf(document.activeElement as HTMLButtonElement);

      switch (e.key) {
        case 'ArrowRight': {
          e.preventDefault();
          const next = (currentIndex + 1) % enabledButtons.length;
          enabledButtons[next]?.focus();
          break;
        }
        case 'ArrowLeft': {
          e.preventDefault();
          const prev = (currentIndex - 1 + enabledButtons.length) % enabledButtons.length;
          enabledButtons[prev]?.focus();
          break;
        }
        case 'Home': {
          e.preventDefault();
          enabledButtons[0]?.focus();
          break;
        }
        case 'End': {
          e.preventDefault();
          enabledButtons[enabledButtons.length - 1]?.focus();
          break;
        }
      }
    });

    // Position above the block element
    this.positionToolbar(toolbar, blockEl);

    document.body.appendChild(toolbar);
    this.toolbarEl = toolbar;

    // Re-position on resize
    this.positionObserver = new ResizeObserver(() => {
      if (this.toolbarEl) {
        this.positionToolbar(this.toolbarEl, blockEl);
      }
    });
    this.positionObserver.observe(blockEl);
  }

  /** Hide the toolbar. */
  hide(): void {
    this.toolbarEl?.remove();
    this.toolbarEl = null;
    this.currentBlockId = null;
    this.positionObserver?.disconnect();
    this.positionObserver = null;
  }

  /** Get the currently targeted block ID. */
  getBlockId(): string | null {
    return this.currentBlockId;
  }

  destroy(): void {
    this.hide();
  }

  private buildActions(block: BlockData): BlockToolbarAction[] {
    const id = block.id;
    const isFirst = this.options.isFirst(id);
    const isLast = this.options.isLast(id);

    return [
      {
        label: 'Move up',
        icon: '\u2191',
        title: 'Move block up (Alt+Up)',
        action: () => this.options.onMoveUp(id),
        disabled: isFirst,
      },
      {
        label: 'Move down',
        icon: '\u2193',
        title: 'Move block down (Alt+Down)',
        action: () => this.options.onMoveDown(id),
        disabled: isLast,
      },
      {
        label: 'Transform',
        icon: '\u21C4',
        title: 'Transform block type',
        action: () => {
          if (this.toolbarEl) {
            this.options.onTransform(id, this.toolbarEl.getBoundingClientRect());
          }
        },
      },
      {
        label: 'Duplicate',
        icon: '\u2398',
        title: 'Duplicate block (Ctrl+D)',
        action: () => this.options.onDuplicate(id),
      },
      {
        label: 'Delete',
        icon: '\u2715',
        title: 'Delete block (Delete)',
        action: () => this.options.onDelete(id),
      },
    ];
  }

  private positionToolbar(toolbar: HTMLElement, blockEl: HTMLElement): void {
    const rect = blockEl.getBoundingClientRect();
    toolbar.style.position = 'fixed';
    toolbar.style.left = `${rect.left}px`;
    toolbar.style.top = `${rect.top - 4}px`;
    toolbar.style.transform = 'translateY(-100%)';
    toolbar.style.zIndex = '1000';
  }

  private formatType(type: string): string {
    return type.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }
}
