/**
 * Block inserter panel for the page builder.
 *
 * `<cms-block-inserter>` displays categorized, searchable block types in a
 * panel. Tracks recently-used blocks in localStorage, supports fuzzy search,
 * outside-click dismissal, and exposes show/hide/toggle methods.
 */

import { blockRegistry, type BlockRegistration } from './BlockRegistry.js';

const RECENT_KEY = 'cms-block-inserter-recent';
const MAX_RECENT = 6;

const CATEGORY_ORDER: ReadonlyArray<{
  key: string;
  label: string;
}> = [
  { key: 'text', label: 'Text' },
  { key: 'media', label: 'Media' },
  { key: 'layout', label: 'Layout' },
  { key: 'interactive', label: 'Interactive' },
  { key: 'data', label: 'Data' },
  { key: 'advanced', label: 'Advanced' },
];

export class CmsBlockInserter extends HTMLElement {
  private searchQuery = '';
  private panel: HTMLElement | null = null;
  private outsideClickHandler: ((e: MouseEvent) => void) | null = null;

  /** Callback invoked when a block type is selected. */
  onSelect: ((type: string) => void) | null = null;

  connectedCallback(): void {
    this.classList.add('bi-root');
    this.render();

    this.outsideClickHandler = (e: MouseEvent) => {
      if (!this.contains(e.target as Node)) {
        this.hide();
      }
    };
    document.addEventListener('click', this.outsideClickHandler, true);
  }

  disconnectedCallback(): void {
    if (this.outsideClickHandler) {
      document.removeEventListener('click', this.outsideClickHandler, true);
      this.outsideClickHandler = null;
    }
  }

  /** Show the inserter panel. */
  show(): void {
    this.panel?.classList.add('bi-panel--visible');
  }

  /** Hide the inserter panel. */
  hide(): void {
    this.panel?.classList.remove('bi-panel--visible');
  }

  /** Toggle the inserter panel visibility. */
  toggle(): void {
    if (this.panel?.classList.contains('bi-panel--visible')) {
      this.hide();
    } else {
      this.show();
    }
  }

  private render(): void {
    this.innerHTML = '';

    const panel = document.createElement('div');
    panel.className = 'bi-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Insert block');
    this.panel = panel;

    // Search
    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'bi-search';
    searchInput.placeholder = 'Search blocks...';
    searchInput.value = this.searchQuery;
    searchInput.setAttribute('aria-label', 'Search blocks');
    searchInput.addEventListener('input', () => {
      this.searchQuery = searchInput.value.toLowerCase().trim();
      this.renderResults();
    });
    panel.appendChild(searchInput);

    // Results container
    const results = document.createElement('div');
    results.className = 'bi-results';
    panel.appendChild(results);

    this.appendChild(panel);
    this.renderResults();
  }

  private renderResults(): void {
    const results = this.panel?.querySelector('.bi-results');
    if (!results) return;
    results.innerHTML = '';

    const allBlocks = blockRegistry.all();

    if (this.searchQuery !== '') {
      const filtered = allBlocks.filter(
        (b) =>
          this.fuzzyMatch(b.label, this.searchQuery) || this.fuzzyMatch(b.type, this.searchQuery),
      );

      if (filtered.length > 0) {
        const grid = this.createBlockGrid(filtered);
        results.appendChild(grid);
      } else {
        const empty = document.createElement('div');
        empty.className = 'bi-results';
        empty.textContent = 'No blocks match your search.';
        results.appendChild(empty);
      }
      return;
    }

    // Recently used
    const recent = this.getRecentTypes();
    if (recent.length > 0) {
      const recentBlocks = recent
        .map((type) => allBlocks.find((b) => b.type === type))
        .filter((b): b is BlockRegistration => b !== undefined);

      if (recentBlocks.length > 0) {
        const section = document.createElement('div');
        section.className = 'bi-section';

        const heading = document.createElement('h4');
        heading.className = 'bi-section__heading';
        heading.textContent = 'Recently Used';
        section.appendChild(heading);

        section.appendChild(this.createBlockGrid(recentBlocks));
        results.appendChild(section);
      }
    }

    // Categories
    for (const cat of CATEGORY_ORDER) {
      const blocks = allBlocks.filter((b) => b.category === cat.key);
      if (blocks.length === 0) continue;

      const section = document.createElement('div');
      section.className = 'bi-section';

      const heading = document.createElement('h4');
      heading.className = 'bi-section__heading';
      heading.textContent = cat.label;
      section.appendChild(heading);

      section.appendChild(this.createBlockGrid(blocks));
      results.appendChild(section);
    }
  }

  private createBlockGrid(blocks: BlockRegistration[]): HTMLElement {
    const grid = document.createElement('div');
    grid.className = 'bi-grid';

    for (const block of blocks) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'bi-block-btn';
      btn.title = block.label;

      const icon = document.createElement('span');
      icon.className = 'bi-block-btn__icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = block.icon;
      btn.appendChild(icon);

      const label = document.createElement('span');
      label.className = 'bi-block-btn__label';
      label.textContent = block.label;
      btn.appendChild(label);

      btn.addEventListener('click', () => {
        this.addToRecent(block.type);
        this.onSelect?.(block.type);
        this.dispatchEvent(
          new CustomEvent('block-insert', {
            bubbles: true,
            detail: { type: block.type },
          }),
        );
        this.hide();
      });

      grid.appendChild(btn);
    }

    return grid;
  }

  private fuzzyMatch(text: string, query: string): boolean {
    const lower = text.toLowerCase();
    if (lower.includes(query)) return true;

    let qi = 0;
    for (let i = 0; i < lower.length && qi < query.length; i++) {
      if (lower[i] === query[qi]) {
        qi++;
      }
    }
    return qi === query.length;
  }

  private getRecentTypes(): string[] {
    try {
      const raw = localStorage.getItem(RECENT_KEY);
      if (!raw) return [];
      const parsed: unknown = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed.filter((item): item is string => typeof item === 'string');
    } catch {
      return [];
    }
  }

  private addToRecent(type: string): void {
    try {
      const recent = this.getRecentTypes().filter((t) => t !== type);
      recent.unshift(type);
      localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, MAX_RECENT)));
    } catch {
      // localStorage unavailable
    }
  }
}

customElements.define('cms-block-inserter', CmsBlockInserter);
