/**
 * Clipboard manager for the page builder.
 *
 * Provides copy, cut, paste, and duplicate operations on blocks using
 * an internal clipboard. Binds Ctrl/Cmd+C/X/V/D keyboard shortcuts
 * and integrates with the undo stack via executeCommand.
 */

import type { PageBuilderStore, BlockData } from './PageBuilderStore.js';
import type { Command } from './UndoStack.js';

interface ClipboardEntry {
  type: string;
  data: Record<string, unknown>;
  children?: Array<{ type: string; data: Record<string, unknown> }>;
}

export class ClipboardManager {
  private readonly store: PageBuilderStore;
  private readonly executeCommand: (command: Command) => void;
  private readonly getSelectedBlockId: () => string | null;
  private clipboard: ClipboardEntry | null = null;
  private cutBlockId: string | null = null;
  private keydownHandler: ((e: KeyboardEvent) => void) | null = null;

  constructor(
    store: PageBuilderStore,
    executeCommand: (command: Command) => void,
    getSelectedBlockId: () => string | null,
  ) {
    this.store = store;
    this.executeCommand = executeCommand;
    this.getSelectedBlockId = getSelectedBlockId;
  }

  /** Attach keyboard listeners to the given element. */
  attach(target: EventTarget): void {
    this.detach(target);

    this.keydownHandler = (e: KeyboardEvent) => {
      this.handleKeydown(e);
    };
    target.addEventListener('keydown', this.keydownHandler as EventListener);
  }

  /** Remove keyboard listeners from the given element. */
  detach(target: EventTarget): void {
    if (this.keydownHandler) {
      target.removeEventListener('keydown', this.keydownHandler as EventListener);
      this.keydownHandler = null;
    }
  }

  /** Copy the currently selected block to the internal clipboard. */
  copy(): void {
    const blockId = this.getSelectedBlockId();
    if (!blockId) return;

    const block = this.store.findBlock(blockId);
    if (!block) return;

    this.clipboard = {
      type: block.type,
      data: structuredClone(block.data),
    };

    if (block.children && block.children.length > 0) {
      this.clipboard.children = block.children.map((child) => ({
        type: child.type,
        data: structuredClone(child.data),
      }));
    }

    this.cutBlockId = null;
  }

  /** Copy the selected block and mark it for removal on paste. */
  cut(): void {
    const blockId = this.getSelectedBlockId();
    if (!blockId) return;

    this.copy();
    this.cutBlockId = blockId;
  }

  /** Paste clipboard contents after the currently selected block. */
  paste(): void {
    if (!this.clipboard) return;

    const selectedId = this.getSelectedBlockId();
    const blocks = this.store.getBlocks();
    const afterIndex = selectedId
      ? blocks.findIndex((b) => b.id === selectedId)
      : blocks.length - 1;

    const insertIndex = afterIndex >= 0 ? afterIndex + 1 : blocks.length;
    const pastedData = structuredClone(this.clipboard.data);
    const pastedType = this.clipboard.type;
    const pastedChildren = this.clipboard.children
      ? this.clipboard.children.map((c) => ({
          type: c.type,
          data: structuredClone(c.data),
        }))
      : undefined;

    const cutId = this.cutBlockId;
    let addedBlockId: string | null = null;

    const command: Command = {
      description: cutId ? 'Cut and paste block' : 'Paste block',
      execute: () => {
        if (cutId) {
          this.store.removeBlock(cutId);
        }
        addedBlockId = this.store.addBlock(pastedType, pastedData, insertIndex);
        if (pastedChildren) {
          const addedBlock = this.store.findBlock(addedBlockId);
          if (addedBlock) {
            addedBlock.children = pastedChildren.map((child) => ({
              id: crypto.randomUUID(),
              type: child.type,
              data: structuredClone(child.data),
            }));
          }
        }
      },
      undo: () => {
        if (addedBlockId) {
          this.store.removeBlock(addedBlockId);
        }
        if (cutId) {
          this.store.addBlock(pastedType, pastedData, insertIndex);
        }
      },
    };

    this.executeCommand(command);
    this.cutBlockId = null;
  }

  /** Duplicate the currently selected block at the next index. */
  duplicate(): void {
    const blockId = this.getSelectedBlockId();
    if (!blockId) return;

    const block = this.store.findBlock(blockId);
    if (!block) return;

    const blocks = this.store.getBlocks();
    const currentIndex = blocks.findIndex((b) => b.id === blockId);
    const insertIndex = currentIndex >= 0 ? currentIndex + 1 : blocks.length;
    const clonedData = structuredClone(block.data);
    const clonedType = block.type;
    const clonedChildren = block.children
      ? block.children.map((child) => ({
          type: child.type,
          data: structuredClone(child.data),
        }))
      : undefined;
    let addedBlockId: string | null = null;

    const command: Command = {
      description: 'Duplicate block',
      execute: () => {
        addedBlockId = this.store.addBlock(clonedType, structuredClone(clonedData), insertIndex);
        if (clonedChildren) {
          const addedBlock = this.store.findBlock(addedBlockId);
          if (addedBlock) {
            addedBlock.children = clonedChildren.map((child) => ({
              id: crypto.randomUUID(),
              type: child.type,
              data: structuredClone(child.data),
            }));
          }
        }
      },
      undo: () => {
        if (addedBlockId) {
          this.store.removeBlock(addedBlockId);
        }
      },
    };

    this.executeCommand(command);
  }

  /** Returns true if the clipboard has content to paste. */
  hasClipboard(): boolean {
    return this.clipboard !== null;
  }

  private handleKeydown(e: KeyboardEvent): void {
    const target = e.target as HTMLElement;

    // Skip when target is an editable element
    if (
      target.tagName === 'INPUT' ||
      target.tagName === 'TEXTAREA' ||
      target.tagName === 'SELECT' ||
      target.isContentEditable
    ) {
      return;
    }

    const isCtrl = e.ctrlKey || e.metaKey;
    if (!isCtrl) return;

    switch (e.key.toLowerCase()) {
      case 'c':
        e.preventDefault();
        this.copy();
        break;
      case 'x':
        e.preventDefault();
        this.cut();
        break;
      case 'v':
        e.preventDefault();
        this.paste();
        break;
      case 'd':
        e.preventDefault();
        this.duplicate();
        break;
    }
  }
}
