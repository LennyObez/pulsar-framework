/**
 * Drag-and-drop manager for reordering blocks in the page builder canvas.
 *
 * Uses the native HTML5 Drag and Drop API. Creates visual drop zone
 * indicators between blocks and inside container blocks (Columns).
 */

import type { PageBuilderStore } from './PageBuilderStore.js';

const DROP_ZONE_CLASS = 'pb-drop-zone';
const DROP_ZONE_ACTIVE_CLASS = 'pb-drop-zone--active';
const DRAGGING_CLASS = 'pb-block--dragging';

export class DragDropManager {
  private readonly store: PageBuilderStore;
  private readonly canvas: HTMLElement;
  private enabled = false;
  private draggedBlockId: string | null = null;
  private dropZones: HTMLElement[] = [];

  private readonly boundDragStart: (e: DragEvent) => void;
  private readonly boundDragEnd: (e: DragEvent) => void;
  private readonly boundDragOver: (e: DragEvent) => void;

  constructor(store: PageBuilderStore, canvas: HTMLElement) {
    this.store = store;
    this.canvas = canvas;

    this.boundDragStart = this.onDragStart.bind(this);
    this.boundDragEnd = this.onDragEnd.bind(this);
    this.boundDragOver = this.onCanvasDragOver.bind(this);
  }

  enable(): void {
    if (this.enabled) {
      return;
    }

    this.enabled = true;
    this.canvas.addEventListener('dragstart', this.boundDragStart);
    this.canvas.addEventListener('dragend', this.boundDragEnd);
    this.canvas.addEventListener('dragover', this.boundDragOver);
  }

  disable(): void {
    if (!this.enabled) {
      return;
    }

    this.enabled = false;
    this.canvas.removeEventListener('dragstart', this.boundDragStart);
    this.canvas.removeEventListener('dragend', this.boundDragEnd);
    this.canvas.removeEventListener('dragover', this.boundDragOver);
    this.clearDropZones();
  }

  destroy(): void {
    this.disable();
  }

  private onDragStart(e: DragEvent): void {
    const blockEl = this.findBlockElement(e.target as HTMLElement);
    if (!blockEl) {
      return;
    }

    const blockId = blockEl.dataset['blockId'];
    if (!blockId) {
      return;
    }

    this.draggedBlockId = blockId;
    blockEl.classList.add(DRAGGING_CLASS);

    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', blockId);

      // Create ghost preview with block type label
      const ghost = document.createElement('div');
      ghost.className = 'pb-drag-ghost';
      ghost.textContent = blockEl.dataset['blockType'] ?? 'Block';
      document.body.appendChild(ghost);
      e.dataTransfer.setDragImage(ghost, 0, 0);
      requestAnimationFrame(() => ghost.remove());
    }

    this.createDropZones();
  }

  private onDragEnd(e: DragEvent): void {
    const blockEl = this.findBlockElement(e.target as HTMLElement);
    if (blockEl) {
      blockEl.classList.remove(DRAGGING_CLASS);
    }

    this.draggedBlockId = null;
    this.clearDropZones();
  }

  private onCanvasDragOver(e: DragEvent): void {
    if (!this.draggedBlockId) {
      return;
    }

    e.preventDefault();
    if (e.dataTransfer) {
      e.dataTransfer.dropEffect = 'move';
    }
  }

  private createDropZones(): void {
    this.clearDropZones();

    const blockElements = this.canvas.querySelectorAll<HTMLElement>('[data-block-id]');
    const topLevelBlocks: HTMLElement[] = [];

    for (const el of blockElements) {
      // Only top-level blocks (direct children of canvas wrapper)
      if (el.parentElement === this.canvas) {
        topLevelBlocks.push(el);
      }
    }

    // Create drop zone before each block and after the last one
    for (let i = 0; i <= topLevelBlocks.length; i++) {
      const zone = document.createElement('div');
      zone.className = DROP_ZONE_CLASS;
      zone.dataset['dropIndex'] = String(i);

      zone.addEventListener('dragover', (ev: DragEvent) => {
        ev.preventDefault();
        ev.stopPropagation();
        zone.classList.add(DROP_ZONE_ACTIVE_CLASS);
      });

      zone.addEventListener('dragleave', () => {
        zone.classList.remove(DROP_ZONE_ACTIVE_CLASS);
      });

      zone.addEventListener('drop', (ev: DragEvent) => {
        ev.preventDefault();
        ev.stopPropagation();
        zone.classList.remove(DROP_ZONE_ACTIVE_CLASS);

        if (this.draggedBlockId) {
          this.store.moveBlock(this.draggedBlockId, i);
        }
      });

      const refBlock = topLevelBlocks[i];
      if (refBlock) {
        this.canvas.insertBefore(zone, refBlock);
      } else {
        this.canvas.appendChild(zone);
      }

      this.dropZones.push(zone);
    }

    // Create drop zones inside container blocks (columns)
    this.createContainerDropZones();
  }

  private createContainerDropZones(): void {
    const columnDropTargets = this.canvas.querySelectorAll<HTMLElement>('[data-column-index]');

    for (const colEl of columnDropTargets) {
      const containerId = colEl.closest<HTMLElement>('[data-block-id]')?.dataset['blockId'];
      if (!containerId) {
        continue;
      }

      const zone = document.createElement('div');
      zone.className = `${DROP_ZONE_CLASS} ${DROP_ZONE_CLASS}--container`;
      zone.textContent = 'Drop here';

      zone.addEventListener('dragover', (ev: DragEvent) => {
        ev.preventDefault();
        ev.stopPropagation();
        zone.classList.add(DROP_ZONE_ACTIVE_CLASS);
      });

      zone.addEventListener('dragleave', () => {
        zone.classList.remove(DROP_ZONE_ACTIVE_CLASS);
      });

      zone.addEventListener('drop', (ev: DragEvent) => {
        ev.preventDefault();
        ev.stopPropagation();
        zone.classList.remove(DROP_ZONE_ACTIVE_CLASS);

        if (this.draggedBlockId) {
          const colIndex = parseInt(colEl.dataset['columnIndex'] ?? '0', 10);
          // Update the block's column index before moving
          const block = this.store.findBlock(this.draggedBlockId);
          if (block) {
            block.data['_columnIndex'] = colIndex;
          }
          this.store.moveBlockToContainer(this.draggedBlockId, containerId, colEl.children.length);
        }
      });

      colEl.appendChild(zone);
      this.dropZones.push(zone);
    }
  }

  private clearDropZones(): void {
    for (const zone of this.dropZones) {
      zone.remove();
    }
    this.dropZones = [];
  }

  private findBlockElement(target: HTMLElement | null): HTMLElement | null {
    let el: HTMLElement | null = target;
    while (el && el !== this.canvas) {
      if (el.dataset['blockId']) {
        return el;
      }
      el = el.parentElement;
    }
    return null;
  }
}
