/**
 * Visual WYSIWYG page editor for the CMS.
 *
 * `<cms-page-editor>` Custom Element orchestrates the full visual editing
 * experience: drag-and-drop block ordering, inline text editing, contextual
 * block toolbars, undo/redo, block transforms, pattern insertion, keyboard
 * navigation, and accessibility (ARIA live regions, focus management).
 *
 * Data flow:
 * 1. On connectedCallback: reads initial blocks from the hidden textarea
 * 2. On edit: store updates trigger canvas re-render + autosave
 * 3. On form submit: serializes store state back to the hidden textarea
 */

import { PageBuilderStore, type BlockData } from './PageBuilderStore.js';
import { DragDropManager } from './DragDropManager.js';
import { UndoStack, type Command } from './UndoStack.js';
import { ResponsivePreview, type PreviewMode } from './ResponsivePreview.js';
import { blockRegistry } from './BlockRegistry.js';
import { CmsBlockInspector } from './BlockInspector.js';
import { ClipboardManager } from './ClipboardManager.js';
import { BlockTemplateManager } from './BlockTemplates.js';
import { SelectionManager } from './SelectionManager.js';
import { BlockToolbar } from './BlockToolbar.js';
import { BlockTransformMenu, blockTransformRegistry } from './BlockTransform.js';
import { PatternInserterPanel, type PatternBlockDefinition } from './PatternInserter.js';

const AUTOSAVE_DEBOUNCE_MS = 2000;
const SLASH_INSERTER_DEBOUNCE_MS = 100;

export class CmsPageEditor extends HTMLElement {
  private store: PageBuilderStore = new PageBuilderStore();
  private undoStack: UndoStack = new UndoStack(100);
  private dragDrop: DragDropManager | null = null;
  private responsivePreview: ResponsivePreview | null = null;
  private selectionManager: SelectionManager | null = null;
  private blockToolbar: BlockToolbar | null = null;
  private transformMenu: BlockTransformMenu | null = null;
  private patternInserter: PatternInserterPanel | null = null;
  private clipboardManager: ClipboardManager;
  private blockTemplates: BlockTemplateManager = new BlockTemplateManager();

  private toolbarEl: HTMLElement | null = null;
  private canvasEl: HTMLElement | null = null;
  private inspectorEl: CmsBlockInspector | null = null;
  private textarea: HTMLTextAreaElement | null = null;
  private undoBtnEl: HTMLButtonElement | null = null;
  private redoBtnEl: HTMLButtonElement | null = null;
  private autosaveTimer: ReturnType<typeof setTimeout> | null = null;
  private previewMode = false;

  constructor() {
    super();
    this.clipboardManager = new ClipboardManager(
      this.store,
      (cmd) => this.undoStack.execute(cmd),
      () => this.selectionManager?.getSingleSelectedId() ?? null,
    );
  }

  static get observedAttributes(): string[] {
    return ['content-id', 'locale', 'api-endpoint', 'autosave-interval'];
  }

  connectedCallback(): void {
    this.classList.add('pb-shell');
    this.setAttribute('role', 'application');
    this.setAttribute('aria-label', 'Page editor');

    this.textarea = this.querySelector<HTMLTextAreaElement>('textarea[name="blocks_json"]');
    if (this.textarea && this.textarea.value.trim() !== '') {
      this.store.fromJSON(this.textarea.value);
    }

    this.buildShell();

    this.store.subscribe(() => {
      this.renderCanvas();
      this.syncTextarea();
      this.scheduleAutosave();
    });

    this.undoStack.onChange = () => this.updateUndoButtons();

    this.renderCanvas();
    this.clipboardManager.attach(this);
    this.setupGlobalKeyboard();

    const form = this.closest('form');
    if (form) {
      form.addEventListener('submit', () => this.syncTextarea());
    }
  }

  disconnectedCallback(): void {
    this.dragDrop?.destroy();
    this.clipboardManager.detach(this);
    this.selectionManager?.destroy();
    this.blockToolbar?.destroy();
    this.transformMenu?.destroy();
    this.patternInserter?.destroy();
    if (this.autosaveTimer) clearTimeout(this.autosaveTimer);
  }

  // ── Shell layout ──────────────────────────────────────────────

  private buildShell(): void {
    // Toolbar
    this.toolbarEl = document.createElement('div');
    this.toolbarEl.className = 'pb-toolbar';
    this.toolbarEl.setAttribute('role', 'toolbar');
    this.toolbarEl.setAttribute('aria-label', 'Page editor toolbar');
    this.buildToolbar();

    // Canvas
    this.canvasEl = document.createElement('div');
    this.canvasEl.className = 'pb-canvas';
    this.canvasEl.setAttribute('role', 'list');
    this.canvasEl.setAttribute('aria-label', 'Page blocks');

    // Inspector
    this.inspectorEl = document.createElement('cms-block-inspector') as CmsBlockInspector;
    this.inspectorEl.setOnBlockUpdate((id, data) => {
      const block = this.store.findBlock(id);
      if (!block) return;
      const previousData = { ...block.data };
      const command: Command = {
        description: 'Update block settings',
        execute: () => this.store.updateBlock(id, data),
        undo: () => this.store.updateBlock(id, previousData),
      };
      this.undoStack.execute(command);
    });

    // Layout
    const layout = document.createElement('div');
    layout.className = 'pb-layout';
    layout.appendChild(this.canvasEl);
    layout.appendChild(this.inspectorEl);

    this.insertBefore(this.toolbarEl, this.firstChild);
    this.insertBefore(layout, this.textarea);

    if (this.textarea) {
      this.textarea.style.display = 'none';
    }

    // Drag and drop
    this.dragDrop = new DragDropManager(this.store, this.canvasEl);
    this.dragDrop.enable();

    // Responsive preview
    this.responsivePreview = new ResponsivePreview(this.canvasEl);

    // Selection manager
    this.selectionManager = new SelectionManager({
      canvas: this.canvasEl,
      onSelectionChange: (state) => this.onSelectionChange(state),
      getBlockIds: () => this.store.getBlocks().map((b) => b.id),
      getBlockLabel: (id) => {
        const block = this.store.findBlock(id);
        return block ? this.formatBlockType(block.type) : 'Unknown';
      },
    });
    this.selectionManager.enable();

    // Block toolbar
    this.blockToolbar = new BlockToolbar({
      onMoveUp: (id) => this.moveBlock(id, 'up'),
      onMoveDown: (id) => this.moveBlock(id, 'down'),
      onDuplicate: (id) => {
        this.selectionManager?.select(id);
        this.clipboardManager.duplicate();
      },
      onDelete: (id) => this.deleteBlock(id),
      onTransform: (id, rect) => this.showTransformMenu(id, rect),
      isFirst: (id) => {
        const blocks = this.store.getBlocks();
        return blocks.length > 0 && blocks[0]?.id === id;
      },
      isLast: (id) => {
        const blocks = this.store.getBlocks();
        return blocks.length > 0 && blocks[blocks.length - 1]?.id === id;
      },
    });

    // Transform menu
    this.transformMenu = new BlockTransformMenu((blockId, toType, newData) => {
      this.transformBlock(blockId, toType, newData);
    });

    // Pattern inserter
    this.patternInserter = new PatternInserterPanel(
      (blocks) => this.insertPattern(blocks),
      () => this.toolbarEl?.querySelector<HTMLButtonElement>('.pb-toolbar__patterns')?.focus(),
    );
  }

  // ── Toolbar ───────────────────────────────────────────────────

  private buildToolbar(): void {
    if (!this.toolbarEl) return;
    this.toolbarEl.textContent = '';

    // Left: Add Block + Patterns
    const leftSection = document.createElement('div');
    leftSection.className = 'pb-toolbar__section';

    const addBtn = this.createToolbarButton(
      '+ Add Block',
      'cms-btn cms-btn--primary pb-toolbar__add-block',
      () => {
        this.showBlockInserter();
      },
    );
    leftSection.appendChild(addBtn);

    const patternsBtn = this.createToolbarButton(
      'Patterns',
      'cms-btn cms-btn--outline pb-toolbar__patterns',
      () => {
        this.showPatternInserter();
      },
    );
    leftSection.appendChild(patternsBtn);

    const templatesBtn = this.createToolbarButton(
      'Templates',
      'cms-btn cms-btn--outline pb-toolbar__templates',
      () => {
        this.showTemplatesMenu();
      },
    );
    leftSection.appendChild(templatesBtn);

    // Center: Undo / Redo / Preview toggle
    const centerSection = document.createElement('div');
    centerSection.className = 'pb-toolbar__section';

    this.undoBtnEl = this.createToolbarButton(
      'Undo',
      'cms-btn cms-btn--outline pb-toolbar__undo',
      () => {
        this.undoStack.undo();
      },
    );
    this.undoBtnEl.disabled = true;
    this.undoBtnEl.setAttribute('aria-label', 'Undo (Ctrl+Z)');
    centerSection.appendChild(this.undoBtnEl);

    this.redoBtnEl = this.createToolbarButton(
      'Redo',
      'cms-btn cms-btn--outline pb-toolbar__redo',
      () => {
        this.undoStack.redo();
      },
    );
    this.redoBtnEl.disabled = true;
    this.redoBtnEl.setAttribute('aria-label', 'Redo (Ctrl+Shift+Z)');
    centerSection.appendChild(this.redoBtnEl);

    const previewToggle = this.createToolbarButton(
      'Preview',
      'cms-btn cms-btn--outline pb-toolbar__preview-toggle',
      () => {
        this.togglePreviewMode();
      },
    );
    previewToggle.setAttribute('aria-pressed', 'false');
    centerSection.appendChild(previewToggle);

    // Right: Responsive modes
    const rightSection = document.createElement('div');
    rightSection.className = 'pb-toolbar__section';

    const modes: Array<{ mode: PreviewMode; label: string }> = [
      { mode: 'desktop', label: 'Desktop' },
      { mode: 'tablet', label: 'Tablet' },
      { mode: 'mobile', label: 'Mobile' },
    ];

    for (const { mode, label } of modes) {
      const btn = this.createToolbarButton(
        label,
        `cms-btn cms-btn--outline pb-toolbar__preview-btn${mode === 'desktop' ? ' pb-toolbar__preview-btn--active' : ''}`,
        () => {
          this.responsivePreview?.setMode(mode);
          this.toolbarEl
            ?.querySelectorAll('.pb-toolbar__preview-btn')
            .forEach((el) => el.classList.remove('pb-toolbar__preview-btn--active'));
          btn.classList.add('pb-toolbar__preview-btn--active');
        },
      );
      btn.dataset['previewMode'] = mode;
      rightSection.appendChild(btn);
    }

    this.toolbarEl.appendChild(leftSection);
    this.toolbarEl.appendChild(centerSection);
    this.toolbarEl.appendChild(rightSection);
  }

  private createToolbarButton(
    text: string,
    className: string,
    onClick: () => void,
  ): HTMLButtonElement {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = className;
    btn.textContent = text;
    btn.addEventListener('click', onClick);
    return btn;
  }

  // ── Canvas rendering ──────────────────────────────────────────

  private renderCanvas(): void {
    if (!this.canvasEl) return;
    this.canvasEl.textContent = '';
    const blocks = this.store.getBlocks();

    if (blocks.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'pb-canvas__empty';
      empty.setAttribute('role', 'status');

      const text1 = document.createTextNode('Click ');
      const strong1 = document.createElement('strong');
      strong1.textContent = '"+ Add Block"';
      const text2 = document.createTextNode(' or ');
      const strong2 = document.createElement('strong');
      strong2.textContent = '"Patterns"';
      const text3 = document.createTextNode(' to start building your page. Press ');
      const kbd = document.createElement('kbd');
      kbd.textContent = '/';
      const text4 = document.createTextNode(' to search for blocks.');

      empty.appendChild(text1);
      empty.appendChild(strong1);
      empty.appendChild(text2);
      empty.appendChild(strong2);
      empty.appendChild(text3);
      empty.appendChild(kbd);
      empty.appendChild(text4);

      this.canvasEl.appendChild(empty);
      return;
    }

    for (const block of blocks) {
      const wrapper = this.createBlockWrapper(block);
      this.canvasEl.appendChild(wrapper);
    }

    // Re-show toolbar for selected block
    const selectedId = this.selectionManager?.getSingleSelectedId();
    if (selectedId) {
      this.showToolbarForBlock(selectedId);
    }
  }

  private createBlockWrapper(block: BlockData): HTMLElement {
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block';
    wrapper.dataset['blockId'] = block.id;
    wrapper.dataset['blockType'] = block.type;
    wrapper.draggable = !this.previewMode;
    wrapper.setAttribute('role', 'listitem');
    wrapper.setAttribute('aria-label', `${this.formatBlockType(block.type)} block`);
    wrapper.setAttribute('tabindex', '-1');

    // Anchor/ID support
    const anchor = block.data['anchor'];
    if (typeof anchor === 'string' && anchor.length > 0) {
      wrapper.id = anchor;
    }

    // Custom CSS class support
    const customClassName = block.data['className'];
    if (typeof customClassName === 'string' && customClassName.length > 0) {
      for (const cls of customClassName.split(/\s+/)) {
        if (cls.length > 0) {
          wrapper.classList.add(cls);
        }
      }
    }

    const isSelected = this.selectionManager?.isSelected(block.id) ?? false;
    if (isSelected) {
      wrapper.classList.add('pb-block--selected');
    }

    if (!this.previewMode) {
      // Type label
      const label = document.createElement('span');
      label.className = 'pb-block__type-label';
      label.textContent = this.formatBlockType(block.type);
      label.setAttribute('aria-hidden', 'true');
      wrapper.appendChild(label);

      // Drag handle
      const handle = document.createElement('div');
      handle.className = 'pb-block__handle';
      handle.textContent = '\u2630';
      handle.title = 'Drag to reorder';
      handle.setAttribute('aria-label', 'Drag handle');
      handle.setAttribute('aria-grabbed', 'false');
      wrapper.appendChild(handle);
    }

    // Block content
    const registration = blockRegistry.get(block.type);
    if (registration) {
      const blockEl = document.createElement(registration.element);
      const component = blockEl as HTMLElement & {
        setData?(data: Record<string, unknown>): void;
        setChildren?(children: Array<{ type: string; data: Record<string, unknown> }>): void;
      };

      component.setData?.(block.data);

      if (block.children && typeof component.setChildren === 'function') {
        component.setChildren(block.children.map((c) => ({ type: c.type, data: c.data })));
      }

      blockEl.addEventListener('block-update', ((e: CustomEvent<Record<string, unknown>>) => {
        e.stopPropagation();
        this.store.updateBlock(block.id, e.detail);
      }) as EventListener);

      wrapper.appendChild(blockEl);
    } else {
      const fallback = document.createElement('div');
      fallback.className = 'pb-block__fallback';
      fallback.textContent = `[${block.type} block]`;
      wrapper.appendChild(fallback);
    }

    // Click to select
    wrapper.addEventListener('click', (e) => {
      if (this.previewMode) return;
      const target = e.target as HTMLElement;
      if (
        target.closest('.pb-block__remove') ||
        target.closest('.pb-block-toolbar') ||
        target.closest('button') ||
        target.closest('a') ||
        target.closest('input') ||
        target.closest('select')
      ) {
        return;
      }
      this.selectionManager?.select(block.id, { shift: e.shiftKey });
    });

    // Focus to select
    wrapper.addEventListener('focus', () => {
      if (!this.previewMode && !this.selectionManager?.isSelected(block.id)) {
        this.selectionManager?.select(block.id, { focus: true });
      }
    });

    return wrapper;
  }

  // ── Selection handling ────────────────────────────────────────

  private onSelectionChange(state: { selectedIds: Set<string>; focusedId: string | null }): void {
    // Update visual selection
    this.canvasEl?.querySelectorAll<HTMLElement>('.pb-block').forEach((el) => {
      const id = el.dataset['blockId'];
      if (id) {
        el.classList.toggle('pb-block--selected', state.selectedIds.has(id));
      }
    });

    // Show/hide block toolbar
    if (state.selectedIds.size === 1) {
      const blockId = [...state.selectedIds][0];
      if (blockId) {
        this.showToolbarForBlock(blockId);
        // Update inspector
        const block = this.store.findBlock(blockId);
        if (block && this.inspectorEl) {
          this.inspectorEl.setBlock(block);
          const schema = this.getBlockSchema(block.type);
          if (schema) {
            this.inspectorEl.setBlockSchema(schema);
          }
        }
      }
    } else {
      this.blockToolbar?.hide();
      if (state.selectedIds.size === 0) {
        this.inspectorEl?.setBlock(null);
      }
    }
  }

  private showToolbarForBlock(blockId: string): void {
    const block = this.store.findBlock(blockId);
    if (!block || !this.canvasEl) return;

    const blockEl = this.canvasEl.querySelector<HTMLElement>(
      `[data-block-id="${CSS.escape(blockId)}"]`,
    );
    if (blockEl) {
      this.blockToolbar?.show(block, blockEl);
    }
  }

  // ── Block operations ──────────────────────────────────────────

  private moveBlock(blockId: string, direction: 'up' | 'down'): void {
    const blocks = this.store.getBlocks();
    const currentIndex = blocks.findIndex((b) => b.id === blockId);
    if (currentIndex < 0) return;

    const newIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;
    if (newIndex < 0 || newIndex >= blocks.length) return;

    const command: Command = {
      description: `Move block ${direction}`,
      execute: () => this.store.moveBlock(blockId, newIndex),
      undo: () => this.store.moveBlock(blockId, currentIndex),
    };
    this.undoStack.execute(command);
    this.selectionManager?.announce(`Block moved ${direction} to position ${newIndex + 1}`);
  }

  private deleteBlock(blockId: string): void {
    const block = this.store.findBlock(blockId);
    if (!block) return;

    const blocks = this.store.getBlocks();
    const removedIndex = blocks.findIndex((b) => b.id === blockId);
    const removedData = structuredClone(block.data);
    const removedType = block.type;
    const removedChildren = block.children ? structuredClone(block.children) : undefined;

    const command: Command = {
      description: `Delete ${removedType} block`,
      execute: () => this.store.removeBlock(blockId),
      undo: () => {
        const restoredId = this.store.addBlock(removedType, removedData, removedIndex);
        if (removedChildren) {
          const restored = this.store.findBlock(restoredId);
          if (restored) {
            restored.children = removedChildren;
          }
        }
      },
    };
    this.undoStack.execute(command);
    this.selectionManager?.deselectAll();
    this.blockToolbar?.hide();
    this.selectionManager?.announce(`${this.formatBlockType(removedType)} block deleted`);
  }

  private transformBlock(blockId: string, toType: string, newData: Record<string, unknown>): void {
    const block = this.store.findBlock(blockId);
    if (!block) return;

    const blocks = this.store.getBlocks();
    const index = blocks.findIndex((b) => b.id === blockId);
    const previousType = block.type;
    const previousData = structuredClone(block.data);

    const command: Command = {
      description: `Transform ${previousType} to ${toType}`,
      execute: () => {
        this.store.removeBlock(blockId);
        this.store.addBlock(toType, newData, index);
      },
      undo: () => {
        // Remove the transformed block (at the same index)
        const current = this.store.getBlocks();
        if (current[index]) {
          this.store.removeBlock(current[index].id);
        }
        this.store.addBlock(previousType, previousData, index);
      },
    };
    this.undoStack.execute(command);
    this.selectionManager?.announce(`Block transformed to ${this.formatBlockType(toType)}`);
  }

  private showTransformMenu(blockId: string, rect: DOMRect): void {
    const block = this.store.findBlock(blockId);
    if (!block) return;
    this.transformMenu?.show(blockId, block.type, block.data, rect);
  }

  // ── Pattern insertion ─────────────────────────────────────────

  private insertPattern(patternBlocks: PatternBlockDefinition[]): void {
    const addedIds: string[] = [];

    const command: Command = {
      description: 'Insert block pattern',
      execute: () => {
        addedIds.length = 0;
        for (const patternBlock of patternBlocks) {
          const id = this.store.addBlock(patternBlock.type, { ...patternBlock.data });
          addedIds.push(id);

          if (patternBlock.children && patternBlock.children.length > 0) {
            const addedBlock = this.store.findBlock(id);
            if (addedBlock) {
              addedBlock.children = patternBlock.children.map((child) => ({
                id: crypto.randomUUID(),
                type: child.type,
                data: { ...child.data },
              }));
            }
          }
        }
      },
      undo: () => {
        for (const id of [...addedIds].reverse()) {
          this.store.removeBlock(id);
        }
      },
    };
    this.undoStack.execute(command);
    this.selectionManager?.announce(`Pattern inserted with ${patternBlocks.length} blocks`);
  }

  private showPatternInserter(): void {
    if (!this.toolbarEl) return;
    this.patternInserter?.show(this);
  }

  // ── Block inserter (with slash command) ───────────────────────

  private showBlockInserter(filterText?: string): void {
    this.querySelector('.pb-inserter')?.remove();

    const inserter = document.createElement('div');
    inserter.className = 'pb-inserter';
    inserter.setAttribute('role', 'dialog');
    inserter.setAttribute('aria-label', 'Insert block');

    // Search input
    const searchWrap = document.createElement('div');
    searchWrap.className = 'pb-inserter__search';

    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'cms-input pb-inserter__search-input';
    searchInput.placeholder = 'Search blocks...';
    searchInput.setAttribute('aria-label', 'Search blocks');
    searchInput.value = filterText ?? '';

    searchInput.addEventListener('input', () => {
      this.filterInserterBlocks(inserter, searchInput.value);
    });

    searchWrap.appendChild(searchInput);
    inserter.appendChild(searchWrap);

    const categories = ['text', 'media', 'layout', 'interactive', 'data', 'advanced'] as const;

    for (const category of categories) {
      const blocks = blockRegistry.getByCategory(category);
      if (blocks.length === 0) continue;

      const section = document.createElement('div');
      section.className = 'pb-inserter__section';

      const heading = document.createElement('h4');
      heading.className = 'pb-inserter__heading';
      heading.textContent = category.charAt(0).toUpperCase() + category.slice(1);
      section.appendChild(heading);

      const grid = document.createElement('div');
      grid.className = 'pb-inserter__grid';

      for (const registration of blocks) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pb-inserter__btn';
        btn.dataset['searchText'] =
          `${registration.label} ${registration.type} ${category}`.toLowerCase();

        const iconSpan = document.createElement('span');
        iconSpan.className = `pb-inserter__icon fa ${registration.icon}`;
        btn.appendChild(iconSpan);

        const labelSpan = document.createElement('span');
        labelSpan.className = 'pb-inserter__label';
        labelSpan.textContent = registration.label;
        btn.appendChild(labelSpan);

        btn.addEventListener('click', () => {
          const defaultData = this.getDefaultData(registration.type);
          let addedId: string;
          const command: Command = {
            description: `Add ${registration.label} block`,
            execute: () => {
              addedId = this.store.addBlock(registration.type, defaultData);
            },
            undo: () => {
              this.store.removeBlock(addedId);
            },
          };
          this.undoStack.execute(command);
          inserter.remove();
          this.selectionManager?.announce(`${registration.label} block added`);
        });

        grid.appendChild(btn);
      }

      section.appendChild(grid);
      inserter.appendChild(section);
    }

    // Close button
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'pb-inserter__close';
    closeBtn.textContent = 'Close';
    closeBtn.addEventListener('click', () => {
      inserter.remove();
      this.toolbarEl?.querySelector<HTMLButtonElement>('.pb-toolbar__add-block')?.focus();
    });
    inserter.appendChild(closeBtn);

    // Escape to close
    inserter.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        inserter.remove();
        this.toolbarEl?.querySelector<HTMLButtonElement>('.pb-toolbar__add-block')?.focus();
      }
    });

    // Close on outside click
    const closeHandler = (e: Event): void => {
      if (!inserter.contains(e.target as Node)) {
        inserter.remove();
        document.removeEventListener('mousedown', closeHandler);
      }
    };
    setTimeout(() => document.addEventListener('mousedown', closeHandler), 0);

    this.toolbarEl?.after(inserter);
    searchInput.focus();

    // Apply initial filter if slash-command text was provided
    if (filterText) {
      this.filterInserterBlocks(inserter, filterText);
    }
  }

  private filterInserterBlocks(inserter: HTMLElement, query: string): void {
    const normalized = query.toLowerCase().trim();
    const buttons = inserter.querySelectorAll<HTMLElement>('.pb-inserter__btn');

    for (const btn of buttons) {
      const searchText = btn.dataset['searchText'] ?? '';
      btn.style.display = normalized === '' || searchText.includes(normalized) ? '' : 'none';
    }

    const sections = inserter.querySelectorAll<HTMLElement>('.pb-inserter__section');
    for (const section of sections) {
      const visibleBtns = section.querySelectorAll<HTMLElement>(
        '.pb-inserter__btn:not([style*="display: none"])',
      );
      section.style.display = visibleBtns.length > 0 ? '' : 'none';
    }
  }

  // ── Templates menu ────────────────────────────────────────────

  private showTemplatesMenu(): void {
    this.querySelector('.pb-templates-menu')?.remove();

    const templates = this.blockTemplates.all();
    const menu = document.createElement('div');
    menu.className = 'pb-templates-menu';

    if (templates.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'pb-templates-menu__empty';
      empty.textContent = 'No saved templates. Use the star icon on a block to save one.';
      menu.appendChild(empty);
    } else {
      for (const template of templates) {
        const item = document.createElement('div');
        item.className = 'pb-templates-menu__item';

        const nameEl = document.createElement('span');
        nameEl.className = 'pb-templates-menu__name';
        nameEl.textContent = template.name;
        item.appendChild(nameEl);

        const typeEl = document.createElement('span');
        typeEl.className = 'pb-templates-menu__type';
        typeEl.textContent = template.blockType;
        item.appendChild(typeEl);

        const insertBtn = document.createElement('button');
        insertBtn.type = 'button';
        insertBtn.className = 'cms-btn cms-btn--outline';
        insertBtn.textContent = 'Insert';
        insertBtn.addEventListener('click', () => {
          this.store.addBlock(template.blockType, { ...template.blockData });
          menu.remove();
        });
        item.appendChild(insertBtn);

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'cms-btn cms-btn--outline';
        deleteBtn.textContent = 'Delete';
        deleteBtn.addEventListener('click', () => {
          this.blockTemplates.remove(template.id);
          this.showTemplatesMenu();
        });
        item.appendChild(deleteBtn);

        menu.appendChild(item);
      }
    }

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'pb-templates-menu__close';
    closeBtn.textContent = 'Close';
    closeBtn.addEventListener('click', () => menu.remove());
    menu.appendChild(closeBtn);

    const closeHandler = (e: Event): void => {
      if (!menu.contains(e.target as Node)) {
        menu.remove();
        document.removeEventListener('mousedown', closeHandler);
      }
    };
    setTimeout(() => document.addEventListener('mousedown', closeHandler), 0);

    this.toolbarEl?.after(menu);
  }

  // ── Global keyboard shortcuts ─────────────────────────────────

  private setupGlobalKeyboard(): void {
    let slashTimer: ReturnType<typeof setTimeout> | null = null;

    this.addEventListener('keydown', (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      const isEditable =
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT' ||
        target.isContentEditable;

      // Slash command: "/" opens block inserter
      if (e.key === '/' && !isEditable && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        if (slashTimer) clearTimeout(slashTimer);
        slashTimer = setTimeout(() => this.showBlockInserter(), SLASH_INSERTER_DEBOUNCE_MS);
        return;
      }

      const isCtrl = e.ctrlKey || e.metaKey;

      // Undo: Ctrl+Z
      if (isCtrl && e.key === 'z' && !e.shiftKey && !isEditable) {
        e.preventDefault();
        this.undoStack.undo();
        return;
      }

      // Redo: Ctrl+Shift+Z or Ctrl+Y
      if (isCtrl && ((e.key === 'z' && e.shiftKey) || e.key === 'y') && !isEditable) {
        e.preventDefault();
        this.undoStack.redo();
        return;
      }

      // Alt+Up/Down: move block
      if (e.altKey && !isEditable) {
        const selectedId = this.selectionManager?.getSingleSelectedId();
        if (selectedId) {
          if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.moveBlock(selectedId, 'up');
          } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.moveBlock(selectedId, 'down');
          }
        }
        return;
      }

      // Delete/Backspace: delete selected block
      if ((e.key === 'Delete' || e.key === 'Backspace') && !isEditable) {
        const selectedIds = this.selectionManager?.getSelectedIds() ?? [];
        if (selectedIds.length === 0) return;

        e.preventDefault();

        if (selectedIds.length > 1) {
          const snapshots = selectedIds
            .map((id) => {
              const block = this.store.findBlock(id);
              const allBlocks = this.store.getBlocks();
              const index = allBlocks.findIndex((b) => b.id === id);
              return block
                ? {
                    id,
                    type: block.type,
                    data: structuredClone(block.data),
                    index,
                    children: block.children ? structuredClone(block.children) : undefined,
                  }
                : null;
            })
            .filter((s): s is NonNullable<typeof s> => s !== null);

          const command: Command = {
            description: `Delete ${snapshots.length} blocks`,
            execute: () => {
              for (const snap of snapshots) {
                this.store.removeBlock(snap.id);
              }
            },
            undo: () => {
              for (const snap of [...snapshots].reverse()) {
                const restoredId = this.store.addBlock(snap.type, snap.data, snap.index);
                if (snap.children) {
                  const restored = this.store.findBlock(restoredId);
                  if (restored) restored.children = snap.children;
                }
              }
            },
          };
          this.undoStack.execute(command);
          this.selectionManager?.deselectAll();
          this.selectionManager?.announce(`${snapshots.length} blocks deleted`);
        } else {
          const id = selectedIds[0];
          if (id) this.deleteBlock(id);
        }
      }
    });
  }

  // ── Preview mode ──────────────────────────────────────────────

  private togglePreviewMode(): void {
    this.previewMode = !this.previewMode;

    const previewBtn = this.toolbarEl?.querySelector<HTMLButtonElement>(
      '.pb-toolbar__preview-toggle',
    );
    if (previewBtn) {
      previewBtn.setAttribute('aria-pressed', String(this.previewMode));
      previewBtn.classList.toggle('pb-toolbar__preview-toggle--active', this.previewMode);
    }

    if (this.previewMode) {
      this.selectionManager?.deselectAll();
      this.blockToolbar?.hide();
      this.dragDrop?.disable();
      this.canvasEl?.classList.add('pb-canvas--preview');
    } else {
      this.dragDrop?.enable();
      this.canvasEl?.classList.remove('pb-canvas--preview');
    }

    this.renderCanvas();
    this.selectionManager?.announce(
      this.previewMode ? 'Preview mode enabled' : 'Edit mode enabled',
    );
  }

  // ── Autosave ──────────────────────────────────────────────────

  private scheduleAutosave(): void {
    if (this.autosaveTimer) clearTimeout(this.autosaveTimer);

    const endpoint = this.getAttribute('api-endpoint');
    const contentId = this.getAttribute('content-id');
    if (!endpoint || !contentId) return;

    const interval = parseInt(
      this.getAttribute('autosave-interval') ?? String(AUTOSAVE_DEBOUNCE_MS),
      10,
    );

    this.autosaveTimer = setTimeout(() => {
      this.autosave(endpoint, contentId);
    }, interval);
  }

  private autosave(endpoint: string, contentId: string): void {
    const body = JSON.stringify({
      content_id: contentId,
      blocks: this.store.toJSON(),
    });

    fetch(endpoint, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body,
    }).catch(() => {
      // Silent fail for autosave — user will submit via form
    });
  }

  // ── Utilities ─────────────────────────────────────────────────

  private syncTextarea(): void {
    if (this.textarea) {
      this.textarea.value = this.store.toJSON();
    }
  }

  private updateUndoButtons(): void {
    if (this.undoBtnEl) this.undoBtnEl.disabled = !this.undoStack.canUndo();
    if (this.redoBtnEl) this.redoBtnEl.disabled = !this.undoStack.canRedo();
  }

  private formatBlockType(type: string): string {
    return type.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    const escaped = div.textContent;
    return escaped ?? '';
  }

  private getBlockSchema(type: string): Record<string, unknown> | null {
    const schemas: Record<string, Record<string, unknown>> = {
      paragraph: {
        properties: {
          text: { type: 'string' },
          alignment: { type: 'string', enum: ['left', 'center', 'right', 'justify'] },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      heading: {
        properties: {
          text: { type: 'string' },
          level: { type: 'integer', minimum: 1, maximum: 6 },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      image: {
        properties: {
          src: { type: 'string' },
          alt: { type: 'string' },
          caption: { type: 'string' },
          alignment: { type: 'string', enum: ['left', 'center', 'right'] },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      gallery: {
        properties: {
          columns: { type: 'integer', minimum: 1, maximum: 6 },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      columns: {
        properties: {
          columnCount: { type: 'integer', minimum: 2, maximum: 4 },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      button: {
        properties: {
          text: { type: 'string' },
          url: { type: 'string', format: 'uri' },
          style: { type: 'string', enum: ['primary', 'secondary', 'outline'] },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      video: {
        properties: {
          src: { type: 'string', format: 'uri' },
          poster: { type: 'string' },
          caption: { type: 'string' },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      quote: {
        properties: {
          text: { type: 'string' },
          citation: { type: 'string' },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      code: {
        properties: {
          code: { type: 'string' },
          language: { type: 'string' },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      list: {
        properties: {
          ordered: { type: 'boolean' },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      alert: {
        properties: {
          type: { type: 'string', enum: ['info', 'warning', 'error', 'success', 'tip'] },
          message: { type: 'string' },
          dismissible: { type: 'boolean' },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      hero: {
        properties: {
          heading: { type: 'string' },
          subtext: { type: 'string' },
          buttonText: { type: 'string' },
          buttonUrl: { type: 'string', format: 'uri' },
          backgroundImage: { type: 'string' },
          overlay: { type: 'boolean' },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      cta: {
        properties: {
          heading: { type: 'string' },
          text: { type: 'string' },
          buttonText: { type: 'string' },
          buttonUrl: { type: 'string', format: 'uri' },
          variant: { type: 'string', enum: ['default', 'centered', 'split'] },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      testimonial: {
        properties: {
          quote: { type: 'string' },
          authorName: { type: 'string' },
          authorTitle: { type: 'string' },
          avatarUrl: { type: 'string' },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      spacer: {
        properties: {
          height: { type: 'integer', minimum: 1, maximum: 500 },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
      separator: {
        properties: {
          style: { type: 'string', enum: ['solid', 'dashed', 'dotted', 'double'] },
          anchor: { type: 'string' },
          className: { type: 'string' },
        },
      },
    };

    return schemas[type] ?? null;
  }

  private getDefaultData(type: string): Record<string, unknown> {
    const defaults: Record<string, Record<string, unknown>> = {
      paragraph: { text: '' },
      heading: { text: '', level: 2 },
      image: { src: '', alt: '' },
      gallery: { images: [], columns: 3 },
      columns: { columnCount: 2 },
      button: { text: 'Click me', url: '#', style: 'primary' },
      video: { src: '' },
      spacer: { height: 40 },
      separator: { style: 'solid' },
      html: { content: '' },
      code: { code: '', language: 'plaintext' },
      alert: { type: 'info', message: 'Alert message...', dismissible: false },
      counter: { value: 100, prefix: '', suffix: '', label: '' },
      icon: { icon: 'fa-star', size: 'lg', color: '' },
      'progress-bar': { value: 50, max: 100, label: 'Progress', color: '' },
      quote: { text: '', citation: '' },
      list: { items: [''], ordered: false },
      audio: { src: '', title: '' },
      embed: { url: '', html: '' },
      cta: {
        heading: 'Your Call to Action',
        text: '',
        buttonText: 'Get Started',
        buttonUrl: '#',
        variant: 'default',
      },
      hero: {
        backgroundImage: '',
        heading: 'Hero Title',
        subtext: '',
        buttonText: '',
        buttonUrl: '',
        overlay: false,
      },
      testimonial: { quote: '', authorName: '', authorTitle: '', avatarUrl: '' },
      'social-links': { links: [] },
      'file-download': { fileUrl: '', fileName: '', fileSize: '', description: '' },
      map: { address: '', zoom: 14, height: 400 },
      'pricing-table': { plans: [] },
      table: { headers: ['Column 1', 'Column 2', 'Column 3'], rows: [['', '', '']] },
      accordion: { items: [{ title: 'Section 1', content: '' }], allowMultiple: false },
      tabs: { tabs: [{ label: 'Tab 1', content: '' }] },
      carousel: { slides: [], autoPlay: false, interval: 5000 },
      'button-group': { buttons: [{ text: 'Button', url: '#', variant: 'primary' }] },
      'contact-form': {},
    };

    return { ...(defaults[type] ?? {}) };
  }
}

customElements.define('cms-page-editor', CmsPageEditor);
