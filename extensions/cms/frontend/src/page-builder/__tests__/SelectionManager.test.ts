/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { SelectionManager, type SelectionState } from '../SelectionManager.js';

function createCanvas(): HTMLElement {
  const canvas = document.createElement('div');
  document.body.appendChild(canvas);
  return canvas;
}

function addBlock(canvas: HTMLElement, id: string, type = 'paragraph'): HTMLElement {
  const el = document.createElement('div');
  el.dataset['blockId'] = id;
  el.dataset['blockType'] = type;
  el.setAttribute('tabindex', '-1');
  canvas.appendChild(el);
  return el;
}

describe('SelectionManager', () => {
  let canvas: HTMLElement;
  let onSelectionChange: ReturnType<typeof vi.fn<(state: SelectionState) => void>>;
  let manager: SelectionManager;
  const blockIds = ['block-1', 'block-2', 'block-3'];

  beforeEach(() => {
    document.body.textContent = '';
    canvas = createCanvas();
    for (const id of blockIds) {
      addBlock(canvas, id);
    }

    onSelectionChange = vi.fn();
    manager = new SelectionManager({
      canvas,
      onSelectionChange,
      getBlockIds: () => blockIds,
      getBlockLabel: (id) => id,
    });
    manager.enable();
  });

  it('selects a single block', () => {
    manager.select('block-1');

    expect(manager.getSingleSelectedId()).toBe('block-1');
    expect(manager.isSelected('block-1')).toBe(true);
    expect(manager.isSelected('block-2')).toBe(false);
    expect(onSelectionChange).toHaveBeenCalled();
  });

  it('replaces selection on single select', () => {
    manager.select('block-1');
    manager.select('block-2');

    expect(manager.getSingleSelectedId()).toBe('block-2');
    expect(manager.isSelected('block-1')).toBe(false);
    expect(manager.isSelected('block-2')).toBe(true);
  });

  it('multi-selects with shift', () => {
    manager.select('block-1');
    manager.select('block-2', { shift: true });

    expect(manager.getSingleSelectedId()).toBeNull(); // >1 selected
    expect(manager.getSelectedIds()).toContain('block-1');
    expect(manager.getSelectedIds()).toContain('block-2');
  });

  it('toggles selection off with shift-click on selected block', () => {
    manager.select('block-1');
    manager.select('block-2', { shift: true });
    manager.select('block-1', { shift: true }); // toggle off

    expect(manager.isSelected('block-1')).toBe(false);
    expect(manager.isSelected('block-2')).toBe(true);
  });

  it('deselects all', () => {
    manager.select('block-1');
    manager.select('block-2', { shift: true });

    manager.deselectAll();
    expect(manager.getSelectedIds()).toHaveLength(0);
    expect(manager.getSingleSelectedId()).toBeNull();
  });

  it('enters and exits editing mode', () => {
    manager.select('block-1');
    manager.enterEditing('block-1');

    expect(manager.isEditing('block-1')).toBe(true);
    expect(manager.isEditing('block-2')).toBe(false);

    manager.exitEditing();
    expect(manager.isEditing('block-1')).toBe(false);
  });

  it('focuses next block', () => {
    manager.select('block-1');
    manager.focusRelative('next');

    expect(manager.getSingleSelectedId()).toBe('block-2');
  });

  it('focuses previous block', () => {
    manager.select('block-2');
    manager.focusRelative('previous');

    expect(manager.getSingleSelectedId()).toBe('block-1');
  });

  it('wraps focus from last to first', () => {
    manager.select('block-3');
    manager.focusRelative('next');

    expect(manager.getSingleSelectedId()).toBe('block-1');
  });

  it('wraps focus from first to last', () => {
    manager.select('block-1');
    manager.focusRelative('previous');

    expect(manager.getSingleSelectedId()).toBe('block-3');
  });

  it('returns state snapshot', () => {
    manager.select('block-2');
    const state = manager.getState();

    expect(state.selectedIds).toBeInstanceOf(Set);
    expect(state.selectedIds.has('block-2')).toBe(true);
    expect(state.focusedId).toBe('block-2');
    expect(state.editingId).toBeNull();
  });

  it('creates an ARIA live region on enable', () => {
    const liveRegion = canvas.querySelector('[role="status"]');
    expect(liveRegion).not.toBeNull();
    expect(liveRegion?.getAttribute('aria-live')).toBe('polite');
  });

  it('cleans up on destroy', () => {
    manager.destroy();
    const liveRegion = canvas.querySelector('[role="status"]');
    expect(liveRegion).toBeNull();
  });
});
