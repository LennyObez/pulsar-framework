/**
 * Block selection and focus management for the page builder.
 *
 * Handles single-block selection, multi-select with Shift+Click,
 * keyboard navigation (Tab/Shift+Tab, Escape), and ARIA live
 * region announcements for screen readers.
 */

export interface SelectionState {
  selectedIds: Set<string>;
  focusedId: string | null;
  editingId: string | null;
}

export interface SelectionManagerOptions {
  canvas: HTMLElement;
  onSelectionChange: (state: SelectionState) => void;
  getBlockIds: () => string[];
  getBlockLabel: (id: string) => string;
}

export class SelectionManager {
  private readonly canvas: HTMLElement;
  private readonly onSelectionChange: (state: SelectionState) => void;
  private readonly getBlockIds: () => string[];
  private readonly getBlockLabel: (id: string) => string;

  private selectedIds: Set<string> = new Set();
  private focusedId: string | null = null;
  private editingId: string | null = null;
  private liveRegion: HTMLElement | null = null;

  private readonly boundKeydown: (e: KeyboardEvent) => void;

  constructor(options: SelectionManagerOptions) {
    this.canvas = options.canvas;
    this.onSelectionChange = options.onSelectionChange;
    this.getBlockIds = options.getBlockIds;
    this.getBlockLabel = options.getBlockLabel;

    this.boundKeydown = this.handleKeydown.bind(this);
  }

  enable(): void {
    this.canvas.addEventListener('keydown', this.boundKeydown);
    this.createLiveRegion();
  }

  disable(): void {
    this.canvas.removeEventListener('keydown', this.boundKeydown);
    this.liveRegion?.remove();
    this.liveRegion = null;
  }

  destroy(): void {
    this.disable();
  }

  /** Select a single block, optionally extending selection with shift. */
  select(blockId: string, options?: { shift?: boolean; focus?: boolean }): void {
    const shift = options?.shift ?? false;
    const focus = options?.focus ?? true;

    if (shift) {
      if (this.selectedIds.has(blockId)) {
        this.selectedIds.delete(blockId);
      } else {
        this.selectedIds.add(blockId);
      }
    } else {
      this.selectedIds.clear();
      this.selectedIds.add(blockId);
    }

    if (focus) {
      this.focusedId = blockId;
    }

    this.editingId = null;
    this.notify();
    this.updateAriaAttributes();

    if (!shift) {
      const label = this.getBlockLabel(blockId);
      const ids = this.getBlockIds();
      const position = ids.indexOf(blockId) + 1;
      this.announce(`${label} block selected, position ${position} of ${ids.length}`);
    }
  }

  /** Deselect all blocks. */
  deselectAll(): void {
    this.selectedIds.clear();
    this.focusedId = null;
    this.editingId = null;
    this.notify();
    this.updateAriaAttributes();
  }

  /** Enter editing mode for the focused block. */
  enterEditing(blockId: string): void {
    this.editingId = blockId;
    this.notify();
  }

  /** Exit editing mode. */
  exitEditing(): void {
    this.editingId = null;
    this.notify();
  }

  /** Get current selection state. */
  getState(): SelectionState {
    return {
      selectedIds: new Set(this.selectedIds),
      focusedId: this.focusedId,
      editingId: this.editingId,
    };
  }

  /** Get the single selected block ID (null if 0 or >1 selected). */
  getSingleSelectedId(): string | null {
    if (this.selectedIds.size === 1) {
      return [...this.selectedIds][0] ?? null;
    }
    return null;
  }

  /** Get all selected block IDs. */
  getSelectedIds(): string[] {
    return [...this.selectedIds];
  }

  /** Check if a block is selected. */
  isSelected(blockId: string): boolean {
    return this.selectedIds.has(blockId);
  }

  /** Check if a block is being edited. */
  isEditing(blockId: string): boolean {
    return this.editingId === blockId;
  }

  /** Move focus to an adjacent block. */
  focusRelative(direction: 'next' | 'previous'): void {
    const ids = this.getBlockIds();
    if (ids.length === 0) return;

    const currentIndex = this.focusedId ? ids.indexOf(this.focusedId) : -1;
    let nextIndex: number;

    if (direction === 'next') {
      nextIndex = currentIndex < ids.length - 1 ? currentIndex + 1 : 0;
    } else {
      nextIndex = currentIndex > 0 ? currentIndex - 1 : ids.length - 1;
    }

    const nextId = ids[nextIndex];
    if (nextId) {
      this.select(nextId);
      this.focusBlockElement(nextId);
    }
  }

  /** Focus the DOM element for a block. */
  focusBlockElement(blockId: string): void {
    // Find by iterating children instead of CSS selector to avoid CSS.escape dependency
    const el = Array.from(this.canvas.querySelectorAll<HTMLElement>('[data-block-id]')).find(
      (child) => child.dataset['blockId'] === blockId,
    );
    el?.focus();
  }

  /** Announce a message via the ARIA live region. */
  announce(message: string): void {
    if (!this.liveRegion) return;
    this.liveRegion.textContent = '';
    // Force re-announcement by clearing then setting in next frame
    requestAnimationFrame(() => {
      if (this.liveRegion) {
        this.liveRegion.textContent = message;
      }
    });
  }

  private handleKeydown(e: KeyboardEvent): void {
    const target = e.target as HTMLElement;

    // Skip when typing in inputs (unless it's a block wrapper)
    if (
      target.tagName === 'INPUT' ||
      target.tagName === 'TEXTAREA' ||
      target.tagName === 'SELECT' ||
      (target.isContentEditable && !target.dataset['blockId'])
    ) {
      return;
    }

    switch (e.key) {
      case 'Tab': {
        if (this.editingId) return; // Let tab work normally inside editing mode
        e.preventDefault();
        this.focusRelative(e.shiftKey ? 'previous' : 'next');
        break;
      }

      case 'Enter': {
        if (this.focusedId && !this.editingId) {
          e.preventDefault();
          this.enterEditing(this.focusedId);
          this.announce('Entered editing mode');
        }
        break;
      }

      case 'Escape': {
        if (this.editingId) {
          e.preventDefault();
          this.exitEditing();
          if (this.focusedId) {
            this.focusBlockElement(this.focusedId);
          }
          this.announce('Exited editing mode');
        } else if (this.selectedIds.size > 0) {
          e.preventDefault();
          this.deselectAll();
          this.announce('All blocks deselected');
        }
        break;
      }

      case 'ArrowUp':
      case 'ArrowDown': {
        if (e.altKey && this.focusedId) {
          // Alt+Arrow = move block (handled by PageEditor)
          return;
        }
        break;
      }

      case 'a': {
        if ((e.ctrlKey || e.metaKey) && !this.editingId) {
          e.preventDefault();
          const ids = this.getBlockIds();
          this.selectedIds = new Set(ids);
          this.notify();
          this.updateAriaAttributes();
          this.announce(`All ${ids.length} blocks selected`);
        }
        break;
      }
    }
  }

  private createLiveRegion(): void {
    this.liveRegion = document.createElement('div');
    this.liveRegion.setAttribute('role', 'status');
    this.liveRegion.setAttribute('aria-live', 'polite');
    this.liveRegion.setAttribute('aria-atomic', 'true');
    this.liveRegion.className = 'sr-only';
    this.liveRegion.style.cssText =
      'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0;';
    this.canvas.appendChild(this.liveRegion);
  }

  private updateAriaAttributes(): void {
    const allBlocks = this.canvas.querySelectorAll<HTMLElement>('[data-block-id]');
    for (const el of allBlocks) {
      const blockId = el.dataset['blockId'];
      if (!blockId) continue;

      const isSelected = this.selectedIds.has(blockId);
      el.setAttribute('aria-selected', String(isSelected));
      el.setAttribute('tabindex', blockId === this.focusedId ? '0' : '-1');
    }
  }

  private notify(): void {
    this.onSelectionChange({
      selectedIds: new Set(this.selectedIds),
      focusedId: this.focusedId,
      editingId: this.editingId,
    });
  }
}
