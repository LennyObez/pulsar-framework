/**
 * Page builder shell for the CMS admin interface.
 *
 * `<cms-page-builder>` Custom Element replaces the JSON textarea in the
 * content form with a visual block editing experience. It manages the
 * PageBuilderStore, DragDropManager, UndoStack, and renders the toolbar,
 * canvas, and inspector sidebar.
 *
 * Data flow:
 * 1. On connectedCallback: reads initial blocks from the hidden textarea
 * 2. On edit: store updates trigger canvas re-render
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

export class CmsPageBuilder extends HTMLElement {
  private store: PageBuilderStore = new PageBuilderStore();
  private undoStack: UndoStack = new UndoStack();
  private dragDrop: DragDropManager | null = null;
  private responsivePreview: ResponsivePreview | null = null;
  private clipboardManager: ClipboardManager = new ClipboardManager(
    this.store,
    (cmd) => this.undoStack.execute(cmd),
    () => this.selectedBlockId,
  );
  private blockTemplates: BlockTemplateManager = new BlockTemplateManager();
  private cleanupClipboard: (() => void) | null = null;

  private toolbarEl: HTMLElement | null = null;
  private canvasEl: HTMLElement | null = null;
  private inspectorEl: CmsBlockInspector | null = null;
  private textarea: HTMLTextAreaElement | null = null;
  private selectedBlockId: string | null = null;

  private undoBtnEl: HTMLButtonElement | null = null;
  private redoBtnEl: HTMLButtonElement | null = null;

  static get observedAttributes(): string[] {
    return ['content-id', 'locale', 'api-endpoint'];
  }

  connectedCallback(): void {
    this.classList.add('pb-shell');

    // Find the hidden textarea inside this element
    this.textarea = this.querySelector<HTMLTextAreaElement>('textarea[name="blocks_json"]');

    // Load initial data
    if (this.textarea && this.textarea.value.trim() !== '') {
      this.store.fromJSON(this.textarea.value);
    }

    // Build the UI
    this.buildShell();

    // Subscribe to store changes
    this.store.subscribe(() => {
      this.renderCanvas();
      this.syncTextarea();
    });

    // Set up undo stack UI updates
    this.undoStack.onChange = () => {
      this.updateUndoButtons();
    };

    // Initial render
    this.renderCanvas();

    // Set up clipboard keyboard shortcuts
    this.clipboardManager.attach(this);
    this.cleanupClipboard = () => {
      this.clipboardManager.detach(this);
    };

    // Intercept form submission to sync data
    const form = this.closest('form');
    if (form) {
      form.addEventListener('submit', () => {
        this.syncTextarea();
      });
    }
  }

  disconnectedCallback(): void {
    this.dragDrop?.destroy();
    this.cleanupClipboard?.();
  }

  private buildShell(): void {
    // Toolbar
    this.toolbarEl = document.createElement('div');
    this.toolbarEl.className = 'pb-toolbar';
    this.buildToolbar();

    // Canvas
    this.canvasEl = document.createElement('div');
    this.canvasEl.className = 'pb-canvas';

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

    // Layout container
    const layout = document.createElement('div');
    layout.className = 'pb-layout';
    layout.appendChild(this.canvasEl);
    layout.appendChild(this.inspectorEl);

    // Insert before the hidden textarea
    this.insertBefore(this.toolbarEl, this.firstChild);
    this.insertBefore(layout, this.textarea);

    // Hide textarea
    if (this.textarea) {
      this.textarea.style.display = 'none';
    }

    // Set up drag and drop
    this.dragDrop = new DragDropManager(this.store, this.canvasEl);
    this.dragDrop.enable();

    // Set up responsive preview
    this.responsivePreview = new ResponsivePreview(this.canvasEl);
  }

  private buildToolbar(): void {
    if (!this.toolbarEl) {
      return;
    }

    this.toolbarEl.innerHTML = '';

    // Left section: Add Block button
    const leftSection = document.createElement('div');
    leftSection.className = 'pb-toolbar__section';

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--primary pb-toolbar__add-block';
    addBtn.textContent = '+ Add Block';
    addBtn.addEventListener('click', () => {
      this.showBlockInserter();
    });
    leftSection.appendChild(addBtn);

    const templatesBtn = document.createElement('button');
    templatesBtn.type = 'button';
    templatesBtn.className = 'cms-btn cms-btn--outline pb-toolbar__templates';
    templatesBtn.textContent = 'Templates';
    templatesBtn.addEventListener('click', () => {
      this.showTemplatesMenu();
    });
    leftSection.appendChild(templatesBtn);

    // Center section: Undo / Redo
    const centerSection = document.createElement('div');
    centerSection.className = 'pb-toolbar__section';

    this.undoBtnEl = document.createElement('button');
    this.undoBtnEl.type = 'button';
    this.undoBtnEl.className = 'cms-btn cms-btn--outline pb-toolbar__undo';
    this.undoBtnEl.textContent = 'Undo';
    this.undoBtnEl.disabled = true;
    this.undoBtnEl.addEventListener('click', () => {
      this.undoStack.undo();
    });
    centerSection.appendChild(this.undoBtnEl);

    this.redoBtnEl = document.createElement('button');
    this.redoBtnEl.type = 'button';
    this.redoBtnEl.className = 'cms-btn cms-btn--outline pb-toolbar__redo';
    this.redoBtnEl.textContent = 'Redo';
    this.redoBtnEl.disabled = true;
    this.redoBtnEl.addEventListener('click', () => {
      this.undoStack.redo();
    });
    centerSection.appendChild(this.redoBtnEl);

    // Right section: Responsive preview
    const rightSection = document.createElement('div');
    rightSection.className = 'pb-toolbar__section';

    const modes: Array<{ mode: PreviewMode; label: string }> = [
      { mode: 'desktop', label: 'Desktop' },
      { mode: 'tablet', label: 'Tablet' },
      { mode: 'mobile', label: 'Mobile' },
    ];

    for (const { mode, label } of modes) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `cms-btn cms-btn--outline pb-toolbar__preview-btn${mode === 'desktop' ? ' pb-toolbar__preview-btn--active' : ''}`;
      btn.textContent = label;
      btn.dataset['previewMode'] = mode;
      btn.addEventListener('click', () => {
        this.responsivePreview?.setMode(mode);
        // Update active state
        this.toolbarEl
          ?.querySelectorAll('.pb-toolbar__preview-btn')
          .forEach((el) => el.classList.remove('pb-toolbar__preview-btn--active'));
        btn.classList.add('pb-toolbar__preview-btn--active');
      });
      rightSection.appendChild(btn);
    }

    this.toolbarEl.appendChild(leftSection);
    this.toolbarEl.appendChild(centerSection);
    this.toolbarEl.appendChild(rightSection);
  }

  private renderCanvas(): void {
    if (!this.canvasEl) {
      return;
    }

    this.canvasEl.innerHTML = '';
    const blocks = this.store.getBlocks();

    if (blocks.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'pb-canvas__empty';
      empty.textContent = 'Click "Add Block" to start building your page';
      this.canvasEl.appendChild(empty);
      return;
    }

    for (const block of blocks) {
      const wrapper = this.createBlockWrapper(block);
      this.canvasEl.appendChild(wrapper);
    }
  }

  private createBlockWrapper(block: BlockData): HTMLElement {
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block';
    wrapper.dataset['blockId'] = block.id;
    wrapper.dataset['blockType'] = block.type;
    wrapper.draggable = true;

    if (block.id === this.selectedBlockId) {
      wrapper.classList.add('pb-block--selected');
    }

    // Type label overlay
    const label = document.createElement('span');
    label.className = 'pb-block__type-label';
    label.textContent = this.formatBlockType(block.type);
    wrapper.appendChild(label);

    // Drag handle
    const handle = document.createElement('div');
    handle.className = 'pb-block__handle';
    handle.innerHTML = '&#9776;';
    handle.title = 'Drag to reorder';
    wrapper.appendChild(handle);

    // Block action buttons
    const actionsBar = document.createElement('div');
    actionsBar.className = 'pb-block__actions';

    // Move Up button (keyboard alternative for drag handle)
    const moveUpBtn = document.createElement('button');
    moveUpBtn.type = 'button';
    moveUpBtn.className = 'pb-block__action-btn';
    moveUpBtn.textContent = '\u2191';
    moveUpBtn.title = 'Move block up';
    moveUpBtn.setAttribute('aria-label', 'Move block up');
    moveUpBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const currentIndex = this.store.getBlocks().findIndex((b) => b.id === block.id);
      if (currentIndex > 0) {
        this.store.moveBlock(block.id, currentIndex - 1);
      }
    });
    actionsBar.appendChild(moveUpBtn);

    // Move Down button (keyboard alternative for drag handle)
    const moveDownBtn = document.createElement('button');
    moveDownBtn.type = 'button';
    moveDownBtn.className = 'pb-block__action-btn';
    moveDownBtn.textContent = '\u2193';
    moveDownBtn.title = 'Move block down';
    moveDownBtn.setAttribute('aria-label', 'Move block down');
    moveDownBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const blocks = this.store.getBlocks();
      const currentIndex = blocks.findIndex((b) => b.id === block.id);
      if (currentIndex < blocks.length - 1) {
        this.store.moveBlock(block.id, currentIndex + 1);
      }
    });
    actionsBar.appendChild(moveDownBtn);

    // Duplicate button
    const dupBtn = document.createElement('button');
    dupBtn.type = 'button';
    dupBtn.className = 'pb-block__action-btn';
    dupBtn.textContent = '\u2398';
    dupBtn.title = 'Duplicate block';
    dupBtn.setAttribute('aria-label', 'Duplicate block');
    dupBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      this.clipboardManager.duplicate();
    });
    actionsBar.appendChild(dupBtn);

    // Save as template button
    const templateBtn = document.createElement('button');
    templateBtn.type = 'button';
    templateBtn.className = 'pb-block__action-btn';
    templateBtn.textContent = '\u2606';
    templateBtn.title = 'Save as template';
    templateBtn.setAttribute('aria-label', 'Save as template');
    templateBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const name = prompt('Template name:');
      if (name) {
        this.blockTemplates.save({
          id: crypto.randomUUID(),
          name,
          description: '',
          blockType: block.type,
          blockData: { ...block.data },
          createdAt: new Date().toISOString(),
        });
      }
    });
    actionsBar.appendChild(templateBtn);

    // Remove button
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'pb-block__remove';
    removeBtn.textContent = '\u{2715}';
    removeBtn.title = 'Remove block';
    removeBtn.setAttribute('aria-label', 'Remove block');
    removeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const removedData = { ...block.data };
      const removedType = block.type;
      const removedIndex = this.store.getBlocks().findIndex((b) => b.id === block.id);

      const command: Command = {
        description: `Remove ${block.type} block`,
        execute: () => this.store.removeBlock(block.id),
        undo: () => {
          this.store.addBlock(removedType, removedData, removedIndex);
        },
      };
      this.undoStack.execute(command);

      if (this.selectedBlockId === block.id) {
        this.selectedBlockId = null;
        this.inspectorEl?.setBlock(null);
      }
    });
    actionsBar.appendChild(removeBtn);

    wrapper.appendChild(actionsBar);

    // Block content element
    const registration = blockRegistry.get(block.type);
    if (registration) {
      const blockEl = document.createElement(registration.element);
      const component = blockEl as HTMLElement & {
        setData?(data: Record<string, unknown>): void;
        setChildren?(
          children: Array<{
            type: string;
            data: Record<string, unknown>;
          }>,
        ): void;
      };

      component.setData?.(block.data);

      if (block.children && typeof component.setChildren === 'function') {
        component.setChildren(
          block.children.map((c) => ({
            type: c.type,
            data: c.data,
          })),
        );
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

    // Select on click
    wrapper.addEventListener('click', (e) => {
      // Don't select if clicking remove button or inner interactive elements
      const target = e.target as HTMLElement;
      if (
        target.closest('.pb-block__remove') ||
        target.closest('button') ||
        target.closest('a') ||
        target.closest('input') ||
        target.closest('select')
      ) {
        return;
      }

      this.selectBlock(block);
    });

    return wrapper;
  }

  private selectBlock(block: BlockData): void {
    // Deselect previous
    this.canvasEl?.querySelector('.pb-block--selected')?.classList.remove('pb-block--selected');

    this.selectedBlockId = block.id;

    // Highlight selected (safe iteration instead of selector interpolation)
    const wrapper = this.canvasEl
      ? Array.from(this.canvasEl.children).find(
          (el) => (el as HTMLElement).dataset['blockId'] === block.id,
        )
      : undefined;
    wrapper?.classList.add('pb-block--selected');

    // Update inspector
    if (this.inspectorEl) {
      this.inspectorEl.setBlock(block);

      // Get schema from registry block types (PHP schemas mirrored here)
      const schema = this.getBlockSchema(block.type);
      if (schema) {
        this.inspectorEl.setBlockSchema(schema);
      }
    }
  }

  private getBlockSchema(type: string): Record<string, unknown> | null {
    // These schemas mirror the PHP BlockTypeInterface::schema() definitions
    const schemas: Record<string, Record<string, unknown>> = {
      paragraph: {
        properties: {
          text: { type: 'string' },
          alignment: {
            type: 'string',
            enum: ['left', 'center', 'right', 'justify'],
          },
        },
      },
      heading: {
        properties: {
          text: { type: 'string' },
          level: { type: 'integer', minimum: 1, maximum: 6 },
        },
      },
      image: {
        properties: {
          src: { type: 'string' },
          alt: { type: 'string' },
          caption: { type: 'string' },
          alignment: {
            type: 'string',
            enum: ['left', 'center', 'right'],
          },
        },
      },
      gallery: {
        properties: {
          columns: { type: 'integer', minimum: 1, maximum: 6 },
        },
      },
      columns: {
        properties: {
          columnCount: { type: 'integer', minimum: 2, maximum: 4 },
        },
      },
      button: {
        properties: {
          text: { type: 'string' },
          url: { type: 'string', format: 'uri' },
          style: {
            type: 'string',
            enum: ['primary', 'secondary', 'outline'],
          },
        },
      },
      video: {
        properties: {
          src: { type: 'string', format: 'uri' },
          poster: { type: 'string' },
          caption: { type: 'string' },
        },
      },
    };

    return schemas[type] ?? null;
  }

  private showBlockInserter(): void {
    // Remove existing inserter
    this.querySelector('.pb-inserter')?.remove();

    const inserter = document.createElement('div');
    inserter.className = 'pb-inserter';

    const categories = ['text', 'media', 'layout', 'interactive', 'data', 'advanced'] as const;

    for (const category of categories) {
      const blocks = blockRegistry.getByCategory(category);
      if (blocks.length === 0) {
        continue;
      }

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
        btn.innerHTML = `<span class="pb-inserter__icon fa ${this.escapeHtml(registration.icon)}"></span><span class="pb-inserter__label">${this.escapeHtml(registration.label)}</span>`;

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
          this.toolbarEl?.querySelector<HTMLButtonElement>('.pb-toolbar__add-block')?.focus();
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

    // Close on outside click — return focus to Add Block button
    const addBlockBtn = this.toolbarEl?.querySelector<HTMLButtonElement>('.pb-toolbar__add-block');
    const closeHandler = (e: Event): void => {
      if (!inserter.contains(e.target as Node)) {
        inserter.remove();
        document.removeEventListener('mousedown', closeHandler);
        addBlockBtn?.focus();
      }
    };
    setTimeout(() => {
      document.addEventListener('mousedown', closeHandler);
    }, 0);

    this.toolbarEl?.after(inserter);

    // Focus the first block button inside the inserter
    const firstBtn = inserter.querySelector<HTMLButtonElement>('.pb-inserter__btn');
    firstBtn?.focus();
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

  private showTemplatesMenu(): void {
    // Remove existing menu
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

    // Close button
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'pb-templates-menu__close';
    closeBtn.textContent = 'Close';
    closeBtn.addEventListener('click', () => menu.remove());
    menu.appendChild(closeBtn);

    // Close on outside click
    const closeHandler = (e: Event): void => {
      if (!menu.contains(e.target as Node)) {
        menu.remove();
        document.removeEventListener('mousedown', closeHandler);
      }
    };
    setTimeout(() => {
      document.addEventListener('mousedown', closeHandler);
    }, 0);

    this.toolbarEl?.after(menu);
  }

  private syncTextarea(): void {
    if (this.textarea) {
      this.textarea.value = this.store.toJSON();
    }
  }

  private updateUndoButtons(): void {
    if (this.undoBtnEl) {
      this.undoBtnEl.disabled = !this.undoStack.canUndo();
    }
    if (this.redoBtnEl) {
      this.redoBtnEl.disabled = !this.undoStack.canRedo();
    }
  }

  private formatBlockType(type: string): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
  }

  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

customElements.define('cms-page-builder', CmsPageBuilder);
