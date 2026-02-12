/**
 * Block inspector sidebar for the page builder.
 *
 * `<cms-block-inspector>` Custom Element that renders a settings form
 * for the currently selected block. Controls are generated from the block's
 * JSON Schema definition.
 */

import type { BlockData } from './PageBuilderStore.js';

interface SchemaProperty {
  type?: string;
  enum?: string[];
  format?: string;
  minimum?: number;
  maximum?: number;
  properties?: Record<string, SchemaProperty>;
}

interface BlockSchema {
  properties?: Record<string, SchemaProperty>;
}

export class CmsBlockInspector extends HTMLElement {
  private currentBlock: BlockData | null = null;
  private currentSchema: BlockSchema | null = null;
  private onBlockUpdate?: (id: string, data: Partial<Record<string, unknown>>) => void;

  connectedCallback(): void {
    this.classList.add('pb-inspector');
    this.renderEmpty();
  }

  setOnBlockUpdate(handler: (id: string, data: Partial<Record<string, unknown>>) => void): void {
    this.onBlockUpdate = handler;
  }

  setBlock(block: BlockData | null): void {
    this.currentBlock = block;
    this.render();
  }

  setBlockSchema(schema: Record<string, unknown>): void {
    this.currentSchema = schema as unknown as BlockSchema;
    this.render();
  }

  private render(): void {
    if (!this.currentBlock) {
      this.renderEmpty();
      return;
    }

    this.innerHTML = '';

    const header = document.createElement('div');
    header.className = 'pb-inspector__header';

    const title = document.createElement('h3');
    title.className = 'pb-inspector__title';
    title.textContent = `${this.formatBlockType(this.currentBlock.type)} Settings`;
    header.appendChild(title);

    this.appendChild(header);

    const form = document.createElement('div');
    form.className = 'pb-inspector__form';

    const properties = this.currentSchema?.properties ?? {};
    const data = this.currentBlock.data;

    for (const [key, schemaProp] of Object.entries(properties)) {
      // Skip internal keys
      if (key.startsWith('_')) {
        continue;
      }

      const group = this.createControl(key, schemaProp, data[key]);
      if (group) {
        form.appendChild(group);
      }
    }

    this.appendChild(form);
  }

  private renderEmpty(): void {
    this.innerHTML = '';
    const empty = document.createElement('div');
    empty.className = 'pb-inspector__empty';
    empty.textContent = 'Select a block to edit its settings';
    this.appendChild(empty);
  }

  private createControl(key: string, schema: SchemaProperty, value: unknown): HTMLElement | null {
    const group = document.createElement('div');
    group.className = 'pb-inspector__group';

    const label = document.createElement('label');
    label.className = 'pb-inspector__label';
    label.textContent = this.formatLabel(key);
    group.appendChild(label);

    // Object with src + alt = media selector
    if (
      schema.type === 'object' &&
      schema.properties &&
      'src' in schema.properties &&
      'alt' in schema.properties
    ) {
      const mediaControl = this.createMediaControl(
        key,
        value as Record<string, unknown> | undefined,
      );
      group.appendChild(mediaControl);
      return group;
    }

    // String with enum = select dropdown
    if (schema.type === 'string' && schema.enum) {
      const select = document.createElement('select');
      select.className = 'cms-input pb-inspector__select';
      label.setAttribute('for', `pb-inspector-${key}`);
      select.id = `pb-inspector-${key}`;

      const emptyOpt = document.createElement('option');
      emptyOpt.value = '';
      emptyOpt.textContent = 'Select...';
      select.appendChild(emptyOpt);

      for (const opt of schema.enum) {
        const option = document.createElement('option');
        option.value = opt;
        option.textContent = this.formatLabel(opt);
        if (value === opt) {
          option.selected = true;
        }
        select.appendChild(option);
      }

      select.addEventListener('change', () => {
        this.updateBlockData(key, select.value || undefined);
      });

      group.appendChild(select);
      return group;
    }

    // Boolean = toggle switch
    if (schema.type === 'boolean') {
      const toggle = document.createElement('div');
      toggle.className = 'pb-inspector__toggle-wrap';

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'pb-inspector__toggle';
      checkbox.id = `pb-inspector-${key}`;
      checkbox.checked = Boolean(value);

      checkbox.addEventListener('change', () => {
        this.updateBlockData(key, checkbox.checked);
      });

      label.setAttribute('for', checkbox.id);
      toggle.appendChild(checkbox);
      group.appendChild(toggle);
      return group;
    }

    // Number
    if (schema.type === 'number' || schema.type === 'integer') {
      const input = document.createElement('input');
      input.type = 'number';
      input.className = 'cms-input pb-inspector__number';
      input.id = `pb-inspector-${key}`;
      label.setAttribute('for', input.id);

      if (typeof value === 'number') {
        input.value = String(value);
      }
      if (schema.minimum !== undefined) {
        input.min = String(schema.minimum);
      }
      if (schema.maximum !== undefined) {
        input.max = String(schema.maximum);
      }
      if (schema.type === 'integer') {
        input.step = '1';
      }

      input.addEventListener('change', () => {
        const parsed =
          schema.type === 'integer' ? parseInt(input.value, 10) : parseFloat(input.value);
        this.updateBlockData(key, isNaN(parsed) ? undefined : parsed);
      });

      group.appendChild(input);
      return group;
    }

    // String with format = color
    if (schema.type === 'string' && schema.format === 'color') {
      const input = document.createElement('input');
      input.type = 'color';
      input.className = 'pb-inspector__color';
      input.id = `pb-inspector-${key}`;
      label.setAttribute('for', input.id);
      input.value = typeof value === 'string' ? value : '#000000';

      input.addEventListener('input', () => {
        this.updateBlockData(key, input.value);
      });

      group.appendChild(input);
      return group;
    }

    // String with format = uri
    if (schema.type === 'string' && schema.format === 'uri') {
      const input = document.createElement('input');
      input.type = 'url';
      input.className = 'cms-input pb-inspector__url';
      input.id = `pb-inspector-${key}`;
      label.setAttribute('for', input.id);
      input.placeholder = 'https://';
      input.value = typeof value === 'string' ? value : '';

      input.addEventListener('change', () => {
        this.updateBlockData(key, input.value);
      });

      group.appendChild(input);
      return group;
    }

    // Default: text input
    if (schema.type === 'string') {
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'cms-input pb-inspector__text';
      input.id = `pb-inspector-${key}`;
      label.setAttribute('for', input.id);
      input.value = typeof value === 'string' ? value : '';

      input.addEventListener('input', () => {
        this.updateBlockData(key, input.value);
      });

      group.appendChild(input);
      return group;
    }

    // Unsupported type — skip
    return null;
  }

  private createMediaControl(key: string, value: Record<string, unknown> | undefined): HTMLElement {
    const wrap = document.createElement('div');
    wrap.className = 'pb-inspector__media';

    const src = typeof value?.['src'] === 'string' ? value['src'] : '';
    const alt = typeof value?.['alt'] === 'string' ? value['alt'] : '';

    if (src) {
      const thumbnail = document.createElement('img');
      thumbnail.className = 'pb-inspector__media-thumb';
      thumbnail.src = src;
      thumbnail.alt = alt;
      wrap.appendChild(thumbnail);
    }

    const srcInput = document.createElement('input');
    srcInput.type = 'text';
    srcInput.className = 'cms-input';
    srcInput.placeholder = 'Image URL';
    srcInput.value = src;

    const altInput = document.createElement('input');
    altInput.type = 'text';
    altInput.className = 'cms-input';
    altInput.placeholder = 'Alt text';
    altInput.value = alt;
    altInput.style.marginTop = '0.375rem';

    const updateMedia = (): void => {
      this.updateBlockData(key, {
        src: srcInput.value,
        alt: altInput.value,
      });
    };

    srcInput.addEventListener('change', updateMedia);
    altInput.addEventListener('change', updateMedia);

    wrap.appendChild(srcInput);
    wrap.appendChild(altInput);
    return wrap;
  }

  private updateBlockData(key: string, value: unknown): void {
    if (!this.currentBlock || !this.onBlockUpdate) {
      return;
    }

    this.onBlockUpdate(this.currentBlock.id, {
      [key]: value,
    });
  }

  private formatLabel(key: string): string {
    return key
      .replace(/([A-Z])/g, ' $1')
      .replace(/[_-]/g, ' ')
      .replace(/^\w/, (c) => c.toUpperCase())
      .trim();
  }

  private formatBlockType(type: string): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
  }
}

customElements.define('cms-block-inspector', CmsBlockInspector);
