/**
 * Page builder accordion block.
 *
 * `<cms-pb-accordion>` renders expandable sections with editable title and
 * content. Supports add/remove items and an allowMultiple option.
 */

interface AccordionItem {
  title: string;
  content: string;
}

let accordionUid = 0;

export class CmsPbAccordion extends HTMLElement {
  private blockData: Record<string, unknown> = {};
  private openIndices: Set<number> = new Set([0]);
  private readonly uid = String(++accordionUid);

  connectedCallback(): void {
    this.classList.add('pb-block-accordion');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getItems(): AccordionItem[] {
    const items = this.blockData['items'];
    if (!Array.isArray(items)) return [];
    return items.filter(
      (item): item is AccordionItem =>
        typeof item === 'object' &&
        item !== null &&
        typeof item.title === 'string' &&
        typeof item.content === 'string',
    );
  }

  private allowMultiple(): boolean {
    return this.blockData['allowMultiple'] === true;
  }

  private render(): void {
    this.innerHTML = '';

    const items = this.getItems();
    const allowMultiple = this.allowMultiple();

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-accordion__wrapper';

    // Multiple toggle
    const controls = document.createElement('div');
    controls.className = 'pb-block-accordion__controls';
    const multiLabel = document.createElement('label');
    multiLabel.className = 'pb-block-accordion__multi-toggle';
    const multiCheck = document.createElement('input');
    multiCheck.type = 'checkbox';
    multiCheck.checked = allowMultiple;
    multiCheck.addEventListener('change', () => {
      this.blockData['allowMultiple'] = multiCheck.checked;
      this.emitUpdate();
    });
    multiLabel.appendChild(multiCheck);
    const multiSpan = document.createElement('span');
    multiSpan.textContent = 'Allow multiple open';
    multiLabel.appendChild(multiSpan);
    controls.appendChild(multiLabel);
    wrapper.appendChild(controls);

    // Accordion items
    const headers: HTMLElement[] = [];
    for (let i = 0; i < items.length; i++) {
      const item = items[i];
      if (!item) continue;
      const isOpen = this.openIndices.has(i);

      const section = document.createElement('div');
      section.className = `pb-block-accordion__item${isOpen ? ' pb-block-accordion__item--open' : ''}`;

      const panelId = `pb-accordion-panel-${i}-${this.uid}`;
      const headerId = `pb-accordion-header-${i}-${this.uid}`;

      // Header (clickable to toggle)
      const header = document.createElement('div');
      header.className = 'pb-block-accordion__header';
      header.id = headerId;
      header.setAttribute('role', 'button');
      header.setAttribute('aria-expanded', String(isOpen));
      header.setAttribute('aria-controls', panelId);
      header.tabIndex = 0;
      headers.push(header);

      const arrow = document.createElement('span');
      arrow.className = 'pb-block-accordion__arrow';
      arrow.textContent = isOpen ? '\u25BC' : '\u25B6';
      arrow.setAttribute('aria-hidden', 'true');
      header.appendChild(arrow);

      const titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.className = 'cms-input pb-block-accordion__title-input';
      titleInput.value = item.title;
      titleInput.placeholder = 'Section title...';
      titleInput.setAttribute('aria-label', `Accordion item ${i + 1} title`);

      const idx = i;
      titleInput.addEventListener('input', () => {
        const current = this.getItems();
        const existing = current[idx];
        if (existing) {
          current[idx] = { ...existing, title: titleInput.value };
          this.blockData['items'] = current;
          this.emitUpdate();
        }
      });

      // Stop click propagation from input to prevent toggle
      titleInput.addEventListener('click', (e) => e.stopPropagation());

      header.appendChild(titleInput);

      // Remove button
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'pb-block-accordion__remove';
      removeBtn.textContent = '\u{2715}';
      removeBtn.title = `Remove item ${i + 1}`;
      removeBtn.setAttribute('aria-label', `Remove item ${i + 1}`);
      removeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const current = this.getItems();
        current.splice(idx, 1);
        this.openIndices.delete(idx);
        this.blockData['items'] = current;
        this.render();
        this.emitUpdate();
      });
      header.appendChild(removeBtn);

      header.addEventListener('click', () => {
        if (this.openIndices.has(idx)) {
          this.openIndices.delete(idx);
        } else {
          if (!allowMultiple) {
            this.openIndices.clear();
          }
          this.openIndices.add(idx);
        }
        this.render();
      });

      header.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          header.click();
        } else if (e.key === 'ArrowDown') {
          e.preventDefault();
          const nextIndex = (idx + 1) % headers.length;
          headers[nextIndex]?.focus();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          const prevIndex = (idx - 1 + headers.length) % headers.length;
          headers[prevIndex]?.focus();
        } else if (e.key === 'Home') {
          e.preventDefault();
          headers[0]?.focus();
        } else if (e.key === 'End') {
          e.preventDefault();
          headers[headers.length - 1]?.focus();
        }
      });

      section.appendChild(header);

      // Content panel
      if (isOpen) {
        const panel = document.createElement('div');
        panel.className = 'pb-block-accordion__panel';
        panel.id = panelId;
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-labelledby', headerId);

        const textarea = document.createElement('textarea');
        textarea.className = 'cms-input pb-block-accordion__content';
        textarea.rows = 3;
        textarea.value = item.content;
        textarea.placeholder = 'Section content...';
        textarea.setAttribute('aria-label', `Accordion item ${i + 1} content`);
        textarea.addEventListener('input', () => {
          const current = this.getItems();
          const existing = current[idx];
          if (existing) {
            current[idx] = { ...existing, content: textarea.value };
            this.blockData['items'] = current;
            this.emitUpdate();
          }
        });

        panel.appendChild(textarea);
        section.appendChild(panel);
      }

      wrapper.appendChild(section);
    }

    // Add item button
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--outline pb-block-accordion__add';
    addBtn.textContent = '+ Add Section';
    addBtn.addEventListener('click', () => {
      const current = this.getItems();
      current.push({ title: 'New Section', content: '' });
      this.blockData['items'] = current;
      this.openIndices.add(current.length - 1);
      this.render();
      this.emitUpdate();
    });
    wrapper.appendChild(addBtn);

    this.appendChild(wrapper);
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

customElements.define('cms-pb-accordion', CmsPbAccordion);
