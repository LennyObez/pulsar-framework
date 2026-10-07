/**
 * Command palette custom element.
 *
 * Triggered by Cmd+K (Mac) / Ctrl+K (Windows). Uses native `<dialog>`
 * for modal overlay with fuzzy search, keyboard navigation, and
 * grouped results.
 */

import { CommandRegistry } from './CommandRegistry.js';
import type { Command } from './CommandRegistry.js';

class CmsCommandPalette extends HTMLElement {
  private dialog: HTMLDialogElement | null = null;
  private input: HTMLInputElement | null = null;
  private resultsContainer: HTMLElement | null = null;
  private selectedIndex = 0;
  private filteredCommands: Command[] = [];
  private debounceTimer: ReturnType<typeof setTimeout> | null = null;

  connectedCallback(): void {
    this.buildDOM();
    this.bindGlobalShortcut();
  }

  disconnectedCallback(): void {
    document.removeEventListener('keydown', this.handleGlobalKeydown);
  }

  private buildDOM(): void {
    const dialog = document.createElement('dialog');
    dialog.className = 'cms-palette__dialog';
    dialog.addEventListener('click', (e) => {
      if (e.target === dialog) {
        this.close();
      }
    });
    dialog.addEventListener('close', () => {
      this.reset();
    });

    const container = document.createElement('div');
    container.className = 'cms-palette';
    container.setAttribute('role', 'combobox');
    container.setAttribute('aria-expanded', 'true');
    container.setAttribute('aria-haspopup', 'listbox');

    const input = document.createElement('input');
    input.className = 'cms-palette__input';
    input.type = 'text';
    input.placeholder = 'Type a command...';
    input.setAttribute('aria-label', 'Search commands');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', 'cms-palette-results');
    input.addEventListener('input', () => {
      this.handleInput();
    });
    input.addEventListener('keydown', (e) => {
      this.handleKeydown(e);
    });

    const shortcutHint = document.createElement('div');
    shortcutHint.className = 'cms-palette__shortcut-hint';
    const isMac = typeof navigator !== 'undefined' && /mac|iphone|ipad/i.test(navigator.userAgent);
    shortcutHint.textContent = isMac ? '\u2318K' : 'Ctrl+K';

    const inputWrapper = document.createElement('div');
    inputWrapper.className = 'cms-palette__input-wrapper';
    inputWrapper.appendChild(input);
    inputWrapper.appendChild(shortcutHint);

    const results = document.createElement('div');
    results.className = 'cms-palette__results';
    results.id = 'cms-palette-results';
    results.setAttribute('role', 'listbox');

    container.appendChild(inputWrapper);
    container.appendChild(results);
    dialog.appendChild(container);
    this.appendChild(dialog);

    this.dialog = dialog;
    this.input = input;
    this.resultsContainer = results;
  }

  private bindGlobalShortcut(): void {
    document.addEventListener('keydown', this.handleGlobalKeydown);
  }

  private readonly handleGlobalKeydown = (e: KeyboardEvent): void => {
    const isMeta = e.metaKey || e.ctrlKey;
    if (isMeta && e.key === 'k') {
      e.preventDefault();
      this.toggle();
    }
  };

  /** Open the command palette. */
  open(): void {
    if (this.dialog === null) return;
    this.dialog.showModal();
    this.filteredCommands = CommandRegistry.all();
    this.selectedIndex = 0;
    this.renderResults();
    this.input?.focus();
  }

  /** Close the command palette. */
  close(): void {
    if (this.dialog === null) return;
    this.dialog.close();
    this.reset();
  }

  /** Toggle open/close. */
  toggle(): void {
    if (this.dialog?.open === true) {
      this.close();
    } else {
      this.open();
    }
  }

  private reset(): void {
    if (this.input !== null) {
      this.input.value = '';
      this.input.removeAttribute('aria-activedescendant');
    }
    this.filteredCommands = [];
    this.selectedIndex = 0;
    if (this.resultsContainer !== null) {
      while (this.resultsContainer.firstChild) {
        this.resultsContainer.removeChild(this.resultsContainer.firstChild);
      }
    }
  }

  private handleInput(): void {
    if (this.debounceTimer !== null) {
      clearTimeout(this.debounceTimer);
    }

    this.debounceTimer = setTimeout(() => {
      const query = this.input?.value ?? '';
      this.filteredCommands = CommandRegistry.search(query);
      this.selectedIndex = 0;
      this.renderResults();
    }, 150);
  }

  private handleKeydown(e: KeyboardEvent): void {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this.selectedIndex = Math.min(this.selectedIndex + 1, this.filteredCommands.length - 1);
        this.updateSelection();
        break;

      case 'ArrowUp':
        e.preventDefault();
        this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
        this.updateSelection();
        break;

      case 'Enter':
        e.preventDefault();
        this.executeSelected();
        break;

      case 'Escape':
        e.preventDefault();
        this.close();
        break;
    }
  }

  private renderResults(): void {
    if (this.resultsContainer === null) return;
    while (this.resultsContainer.firstChild) {
      this.resultsContainer.removeChild(this.resultsContainer.firstChild);
    }

    if (this.filteredCommands.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'cms-palette__empty';
      empty.textContent = 'No commands found';
      this.resultsContainer.appendChild(empty);
      this.input?.removeAttribute('aria-activedescendant');
      return;
    }

    // Group by category
    const groups = new Map<string, Command[]>();
    for (const cmd of this.filteredCommands) {
      const existing = groups.get(cmd.category);
      if (existing !== undefined) {
        existing.push(cmd);
      } else {
        groups.set(cmd.category, [cmd]);
      }
    }

    let flatIndex = 0;

    for (const [category, commands] of groups) {
      const header = document.createElement('div');
      header.className = 'cms-palette__category';
      header.textContent = category;
      header.setAttribute('role', 'presentation');
      this.resultsContainer.appendChild(header);

      for (const cmd of commands) {
        const item = document.createElement('div');
        item.className = 'cms-palette__item';
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', String(flatIndex === this.selectedIndex));
        item.id = `cms-palette-item-${flatIndex}`;
        item.dataset['index'] = String(flatIndex);

        if (flatIndex === this.selectedIndex) {
          item.classList.add('cms-palette__item--selected');
        }

        if (cmd.icon !== undefined) {
          const icon = document.createElement('span');
          icon.className = `cms-palette__icon ${cmd.icon}`;
          icon.setAttribute('aria-hidden', 'true');
          item.appendChild(icon);
        }

        const label = document.createElement('span');
        label.className = 'cms-palette__label';
        label.textContent = cmd.label;
        item.appendChild(label);

        const capturedIndex = flatIndex;
        item.addEventListener('click', () => {
          this.selectedIndex = capturedIndex;
          this.executeSelected();
        });

        item.addEventListener('mouseenter', () => {
          this.selectedIndex = capturedIndex;
          this.updateSelection();
        });

        this.resultsContainer.appendChild(item);
        flatIndex++;
      }
    }

    this.updateActiveDescendant();
  }

  private updateSelection(): void {
    if (this.resultsContainer === null) return;

    const items = this.resultsContainer.querySelectorAll<HTMLElement>('.cms-palette__item');

    for (const item of items) {
      const idx = Number(item.dataset['index']);
      const isSelected = idx === this.selectedIndex;
      item.classList.toggle('cms-palette__item--selected', isSelected);
      item.setAttribute('aria-selected', String(isSelected));

      if (isSelected) {
        item.scrollIntoView({ block: 'nearest' });
      }
    }

    this.updateActiveDescendant();
  }

  private updateActiveDescendant(): void {
    if (this.input === null) return;

    if (
      this.filteredCommands.length === 0 ||
      this.selectedIndex < 0 ||
      this.selectedIndex >= this.filteredCommands.length
    ) {
      this.input.removeAttribute('aria-activedescendant');
      return;
    }

    this.input.setAttribute('aria-activedescendant', `cms-palette-item-${this.selectedIndex}`);
  }

  private executeSelected(): void {
    const cmd = this.filteredCommands[this.selectedIndex];
    if (cmd !== undefined) {
      this.close();
      cmd.action();
    }
  }
}

customElements.define('cms-command-palette', CmsCommandPalette);

export { CmsCommandPalette };
