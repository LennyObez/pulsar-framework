/**
 * Page builder list block.
 *
 * `<cms-pb-list>` renders an ordered or unordered list with editable items.
 * Items can be added, removed, and reordered.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

export class CmsPbList extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-list');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getItems(): string[] {
    const items = this.blockData['items'];
    if (Array.isArray(items)) {
      return items.filter((item): item is string => typeof item === 'string');
    }
    return [''];
  }

  private isOrdered(): boolean {
    return this.blockData['ordered'] === true;
  }

  private render(): void {
    this.innerHTML = '';

    const items = this.getItems();
    const ordered = this.isOrdered();

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-list__wrapper';

    // Toggle ordered/unordered
    const controls = document.createElement('div');
    controls.className = 'pb-block-list__controls';

    const ulBtn = document.createElement('button');
    ulBtn.type = 'button';
    ulBtn.className = `pb-block-list__type-btn${!ordered ? ' pb-block-list__type-btn--active' : ''}`;
    ulBtn.textContent = 'Bulleted';
    ulBtn.addEventListener('click', () => {
      this.blockData['ordered'] = false;
      this.render();
      this.emitUpdate();
    });
    controls.appendChild(ulBtn);

    const olBtn = document.createElement('button');
    olBtn.type = 'button';
    olBtn.className = `pb-block-list__type-btn${ordered ? ' pb-block-list__type-btn--active' : ''}`;
    olBtn.textContent = 'Numbered';
    olBtn.addEventListener('click', () => {
      this.blockData['ordered'] = true;
      this.render();
      this.emitUpdate();
    });
    controls.appendChild(olBtn);

    wrapper.appendChild(controls);

    // List items editor
    const itemsContainer = document.createElement('div');
    itemsContainer.className = 'pb-block-list__items';

    for (let i = 0; i < items.length; i++) {
      const row = document.createElement('div');
      row.className = 'pb-block-list__item-row';

      const marker = document.createElement('span');
      marker.className = 'pb-block-list__marker';
      marker.textContent = ordered ? `${i + 1}.` : '\u2022';
      row.appendChild(marker);

      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'cms-input pb-block-list__item-input';
      input.value = items[i] ?? '';
      input.placeholder = 'List item...';
      input.setAttribute('aria-label', `List item ${i + 1}`);

      const index = i;
      input.addEventListener('input', () => {
        const current = this.getItems();
        current[index] = input.value;
        this.blockData['items'] = current;
        this.emitUpdate();
      });

      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          this.addItemAfter(index);
        }
        if (e.key === 'Backspace' && input.value === '' && items.length > 1) {
          e.preventDefault();
          this.removeItem(index);
        }
      });

      row.appendChild(input);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'pb-block-list__remove-btn';
      removeBtn.textContent = '\u{2715}';
      removeBtn.title = `Remove item ${i + 1}`;
      removeBtn.setAttribute('aria-label', `Remove item ${i + 1}`);
      removeBtn.addEventListener('click', () => {
        this.removeItem(index);
      });
      row.appendChild(removeBtn);

      itemsContainer.appendChild(row);
    }

    wrapper.appendChild(itemsContainer);

    // Add item button
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--outline pb-block-list__add-btn';
    addBtn.textContent = '+ Add Item';
    addBtn.addEventListener('click', () => {
      this.addItemAfter(items.length - 1);
    });
    wrapper.appendChild(addBtn);

    this.appendChild(wrapper);
  }

  private addItemAfter(index: number): void {
    const items = this.getItems();
    items.splice(index + 1, 0, '');
    this.blockData['items'] = items;
    this.render();
    this.emitUpdate();

    // Focus the new input
    const inputs = this.querySelectorAll<HTMLInputElement>('.pb-block-list__item-input');
    inputs[index + 1]?.focus();
  }

  private removeItem(index: number): void {
    const items = this.getItems();
    if (items.length <= 1) return;
    items.splice(index, 1);
    this.blockData['items'] = items;
    this.render();
    this.emitUpdate();
  }

  private emitUpdate(): void {
    this.dispatchEvent(
      new CustomEvent('block-update', {
        bubbles: true,
        detail: this.getData(),
      }),
    );
  }
}

customElements.define('cms-pb-list', CmsPbList);
