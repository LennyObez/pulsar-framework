/**
 * Pulsar Admin Console — root shell component.
 *
 * Provides the top-level layout: header, navigation sidebar, content viewport.
 *
 * DOM is built imperatively via document.createElement to avoid any innerHTML-based
 * XSS surface, even with static markup. Style block is attached as a CSSStyleSheet
 * via the Constructable Stylesheet Object Model where supported.
 */

const STYLES = `
  :host {
    display: grid;
    grid-template-columns: 240px 1fr;
    grid-template-rows: 56px 1fr;
    min-height: 100vh;
  }
  header {
    grid-column: 1 / -1;
    background: var(--color-surface);
    border-block-end: 1px solid var(--color-border);
    padding-inline: var(--space-md);
    display: flex;
    align-items: center;
    font-family: var(--font-display);
    font-weight: 700;
  }
  nav {
    background: var(--color-surface);
    border-inline-end: 1px solid var(--color-border);
    padding: var(--space-md);
  }
  main {
    padding: var(--space-lg);
    overflow-y: auto;
  }
`;

const sheet = new CSSStyleSheet();
sheet.replaceSync(STYLES);

class AdminShell extends HTMLElement {
  /** @type {ShadowRoot} */
  #shadow;

  constructor() {
    super();
    this.#shadow = this.attachShadow({ mode: 'open' });
    this.#shadow.adoptedStyleSheets = [sheet];

    const header = document.createElement('header');
    header.textContent = 'Pulsar Admin';

    const nav = document.createElement('nav');
    const navSlot = document.createElement('slot');
    navSlot.setAttribute('name', 'nav');
    navSlot.textContent = 'Navigation';
    nav.appendChild(navSlot);

    const main = document.createElement('main');
    const mainSlot = document.createElement('slot');
    mainSlot.textContent = 'Welcome to Pulsar Admin Console.';
    main.appendChild(mainSlot);

    this.#shadow.append(header, nav, main);
  }
}

customElements.define('pulsar-admin-shell', AdminShell);
