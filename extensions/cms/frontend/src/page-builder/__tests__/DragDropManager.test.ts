/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { PageBuilderStore } from '../PageBuilderStore.js';
import { DragDropManager } from '../DragDropManager.js';

describe('DragDropManager', () => {
  let store: PageBuilderStore;
  let canvas: HTMLElement;
  let manager: DragDropManager;

  beforeEach(() => {
    document.body.textContent = '';
    store = new PageBuilderStore();
    canvas = document.createElement('div');
    document.body.appendChild(canvas);
    manager = new DragDropManager(store, canvas);
  });

  it('can be enabled and disabled', () => {
    manager.enable();
    manager.disable();
    // Should not throw
    manager.enable();
    manager.destroy();
  });

  it('enable is idempotent', () => {
    manager.enable();
    manager.enable(); // second call should be no-op
    manager.destroy();
  });

  it('disable is idempotent', () => {
    manager.disable(); // already disabled
    manager.disable(); // no-op
  });

  it('destroy removes event listeners', () => {
    manager.enable();
    manager.destroy();
    // Subsequent operations should not throw
    canvas.dispatchEvent(new Event('dragstart'));
    canvas.dispatchEvent(new Event('dragend'));
  });

  it('creates drop zones on drag start', () => {
    store.addBlock('paragraph', { text: 'A' });
    store.addBlock('paragraph', { text: 'B' });

    // Simulate canvas with block elements
    const blocks = store.getBlocks();
    for (const block of blocks) {
      const el = document.createElement('div');
      el.dataset['blockId'] = block.id;
      el.draggable = true;
      canvas.appendChild(el);
    }

    manager.enable();

    // Simulate dragstart on first block
    const firstBlock = canvas.querySelector('[data-block-id]') as HTMLElement;
    const dragEvent = new Event('dragstart', { bubbles: true }) as DragEvent;
    Object.defineProperty(dragEvent, 'target', { value: firstBlock });
    Object.defineProperty(dragEvent, 'dataTransfer', {
      value: {
        effectAllowed: '',
        setData: vi.fn(),
        setDragImage: vi.fn(),
      },
    });

    canvas.dispatchEvent(dragEvent);

    // Drop zones should be created
    const dropZones = canvas.querySelectorAll('.pb-drop-zone');
    expect(dropZones.length).toBeGreaterThan(0);

    manager.destroy();
  });
});
