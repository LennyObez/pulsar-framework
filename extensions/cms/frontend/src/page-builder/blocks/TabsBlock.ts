/**
 * Page builder tabs block.
 *
 * `<cms-pb-tabs>` renders tabbed content with editable tab labels and
 * content panels. Supports add/remove tabs.
 */

let tabsUid = 0;

export class CmsPbTabs extends HTMLElement {
  private blockData: Record<string, unknown> = {};
  private activeTabIndex = 0;
  private readonly uid = String(++tabsUid);

  connectedCallback(): void {
    this.classList.add('pb-block-tabs');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getTabs(): Array<{ label: string; content: string }> {
    const tabs = this.blockData['tabs'];
    if (!Array.isArray(tabs)) return [];
    return tabs.filter(
      (tab): tab is { label: string; content: string } =>
        typeof tab === 'object' &&
        tab !== null &&
        typeof tab.label === 'string' &&
        typeof tab.content === 'string',
    );
  }

  private render(): void {
    this.innerHTML = '';

    const tabs = this.getTabs();
    if (this.activeTabIndex >= tabs.length) {
      this.activeTabIndex = Math.max(0, tabs.length - 1);
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-tabs__wrapper';

    // Tab bar
    const tabBar = document.createElement('div');
    tabBar.className = 'pb-block-tabs__bar';
    tabBar.setAttribute('role', 'tablist');

    const panelId = `pb-tabs-panel-${this.uid}`;

    for (let i = 0; i < tabs.length; i++) {
      const tab = tabs[i];
      if (!tab) continue;
      const isActive = i === this.activeTabIndex;

      const tabBtn = document.createElement('div');
      tabBtn.className = `pb-block-tabs__tab${isActive ? ' pb-block-tabs__tab--active' : ''}`;
      tabBtn.id = `pb-tab-${i}-${this.uid}`;
      tabBtn.setAttribute('role', 'tab');
      tabBtn.setAttribute('aria-selected', String(isActive));
      tabBtn.setAttribute('aria-controls', panelId);
      tabBtn.tabIndex = isActive ? 0 : -1;

      const labelInput = document.createElement('input');
      labelInput.type = 'text';
      labelInput.className = 'pb-block-tabs__label-input';
      labelInput.value = tab.label;
      labelInput.placeholder = 'Tab label';
      labelInput.setAttribute('aria-label', `Tab ${i + 1} label`);

      const idx = i;
      labelInput.addEventListener('input', () => {
        const current = this.getTabs();
        const existing = current[idx];
        if (existing) {
          current[idx] = { ...existing, label: labelInput.value };
          this.blockData['tabs'] = current;
          this.emitUpdate();
        }
      });

      labelInput.addEventListener('click', (e) => e.stopPropagation());
      labelInput.addEventListener('focus', () => {
        this.activeTabIndex = idx;
        this.render();
      });

      tabBtn.appendChild(labelInput);

      // Remove tab button
      if (tabs.length > 1) {
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'pb-block-tabs__remove-tab';
        removeBtn.textContent = '\u{2715}';
        removeBtn.title = `Remove tab ${i + 1}`;
        removeBtn.setAttribute('aria-label', `Remove tab ${i + 1}`);
        removeBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          const current = this.getTabs();
          current.splice(idx, 1);
          this.blockData['tabs'] = current;
          if (this.activeTabIndex >= current.length) {
            this.activeTabIndex = Math.max(0, current.length - 1);
          }
          this.render();
          this.emitUpdate();
        });
        tabBtn.appendChild(removeBtn);
      }

      tabBtn.addEventListener('click', () => {
        this.activeTabIndex = idx;
        this.render();
      });

      tabBtn.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') {
          e.preventDefault();
          const nextIndex = (idx + 1) % tabs.length;
          this.activeTabIndex = nextIndex;
          this.render();
          // Focus the newly active tab after re-render
          requestAnimationFrame(() => {
            const newTab = this.querySelector<HTMLElement>(`#pb-tab-${nextIndex}-${this.uid}`);
            newTab?.focus();
          });
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          const prevIndex = (idx - 1 + tabs.length) % tabs.length;
          this.activeTabIndex = prevIndex;
          this.render();
          requestAnimationFrame(() => {
            const newTab = this.querySelector<HTMLElement>(`#pb-tab-${prevIndex}-${this.uid}`);
            newTab?.focus();
          });
        } else if (e.key === 'Home') {
          e.preventDefault();
          this.activeTabIndex = 0;
          this.render();
          requestAnimationFrame(() => {
            const newTab = this.querySelector<HTMLElement>(`#pb-tab-0-${this.uid}`);
            newTab?.focus();
          });
        } else if (e.key === 'End') {
          e.preventDefault();
          this.activeTabIndex = tabs.length - 1;
          this.render();
          requestAnimationFrame(() => {
            const newTab = this.querySelector<HTMLElement>(
              `#pb-tab-${tabs.length - 1}-${this.uid}`,
            );
            newTab?.focus();
          });
        }
      });

      tabBar.appendChild(tabBtn);
    }

    // Add tab button in tab bar
    const addTabBtn = document.createElement('button');
    addTabBtn.type = 'button';
    addTabBtn.className = 'pb-block-tabs__add-tab';
    addTabBtn.textContent = '+';
    addTabBtn.title = 'Add tab';
    addTabBtn.setAttribute('aria-label', 'Add tab');
    addTabBtn.addEventListener('click', () => {
      const current = this.getTabs();
      current.push({ label: `Tab ${current.length + 1}`, content: '' });
      this.blockData['tabs'] = current;
      this.activeTabIndex = current.length - 1;
      this.render();
      this.emitUpdate();
    });
    tabBar.appendChild(addTabBtn);

    wrapper.appendChild(tabBar);

    // Active tab content panel
    const activeTab = tabs[this.activeTabIndex];
    if (tabs.length > 0 && activeTab) {
      const panel = document.createElement('div');
      panel.className = 'pb-block-tabs__panel';
      panel.id = panelId;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', `pb-tab-${this.activeTabIndex}-${this.uid}`);

      const textarea = document.createElement('textarea');
      textarea.className = 'cms-input pb-block-tabs__content';
      textarea.rows = 5;
      textarea.value = activeTab.content;
      textarea.placeholder = 'Tab content...';
      textarea.setAttribute('aria-label', `Content for ${activeTab.label}`);

      const activeIdx = this.activeTabIndex;
      textarea.addEventListener('input', () => {
        const current = this.getTabs();
        const existing = current[activeIdx];
        if (existing) {
          current[activeIdx] = { ...existing, content: textarea.value };
          this.blockData['tabs'] = current;
          this.emitUpdate();
        }
      });

      panel.appendChild(textarea);
      wrapper.appendChild(panel);
    }

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

customElements.define('cms-pb-tabs', CmsPbTabs);
