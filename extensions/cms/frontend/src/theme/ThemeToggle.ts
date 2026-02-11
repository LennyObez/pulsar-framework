/**
 * <cms-theme-toggle> — Dark/light mode toggle with system preference detection.
 *
 * Reads `prefers-color-scheme` on first visit, persists choice to the
 * `cms_theme` cookie (readable server-side for SSR hints), and sets
 * `data-theme` on the document element.
 */

const COOKIE_NAME = 'cms_theme';
const ATTR_NAME = 'data-theme';

type Theme = 'light' | 'dark';

const SUN_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>`;

const MOON_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;

function getCookie(name: string): string | null {
  const match = document.cookie.match(
    new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'),
  );
  return match?.[1] !== undefined ? decodeURIComponent(match[1]) : null;
}

function setCookie(name: string, value: string, days: number = 365): void {
  const expires = new Date(Date.now() + days * 864e5).toUTCString();
  const secure = location.protocol === 'https:' ? ';Secure' : '';
  document.cookie = `${name}=${encodeURIComponent(value)};expires=${expires};path=/;SameSite=Lax${secure}`;
}

function getSystemPreference(): Theme {
  if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    return 'dark';
  }
  return 'light';
}

function resolveInitialTheme(): Theme {
  const stored = getCookie(COOKIE_NAME);
  if (stored === 'dark' || stored === 'light') {
    return stored;
  }

  const htmlAttr = document.documentElement.getAttribute(ATTR_NAME);
  if (htmlAttr === 'dark' || htmlAttr === 'light') {
    return htmlAttr;
  }

  return getSystemPreference();
}

class CmsThemeToggle extends HTMLElement {
  private currentTheme: Theme = 'light';
  private button: HTMLButtonElement | null = null;
  private mediaQuery: MediaQueryList | null = null;
  private mediaHandler: ((e: MediaQueryListEvent) => void) | null = null;

  connectedCallback(): void {
    this.currentTheme = resolveInitialTheme();
    this.applyTheme(this.currentTheme);

    this.button = document.createElement('button');
    this.button.type = 'button';
    this.button.setAttribute('role', 'switch');
    this.button.setAttribute('aria-label', 'Toggle dark mode');
    this.button.setAttribute('aria-checked', this.currentTheme === 'dark' ? 'true' : 'false');
    this.button.style.cssText =
      'background:none;border:none;cursor:pointer;padding:0.375rem;color:inherit;display:inline-flex;align-items:center;justify-content:center;border-radius:0.25rem;';
    this.button.innerHTML = this.currentTheme === 'dark' ? SUN_SVG : MOON_SVG;
    this.button.addEventListener('click', this.toggle);
    this.appendChild(this.button);

    // Watch for system preference changes (only when user hasn't explicitly chosen)
    if (window.matchMedia) {
      this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
      this.mediaHandler = (e: MediaQueryListEvent): void => {
        // Only follow system if no cookie override
        if (getCookie(COOKIE_NAME) === null) {
          const newTheme: Theme = e.matches ? 'dark' : 'light';
          this.setTheme(newTheme, false);
        }
      };
      this.mediaQuery.addEventListener('change', this.mediaHandler);
    }
  }

  disconnectedCallback(): void {
    this.button?.removeEventListener('click', this.toggle);
    if (this.mediaQuery && this.mediaHandler) {
      this.mediaQuery.removeEventListener('change', this.mediaHandler);
    }
  }

  private toggle = (): void => {
    const newTheme: Theme = this.currentTheme === 'dark' ? 'light' : 'dark';
    this.setTheme(newTheme, true);
  };

  private setTheme(theme: Theme, persist: boolean): void {
    this.currentTheme = theme;
    this.applyTheme(theme);

    if (this.button) {
      this.button.setAttribute('aria-checked', theme === 'dark' ? 'true' : 'false');
      this.button.innerHTML = theme === 'dark' ? SUN_SVG : MOON_SVG;
    }

    if (persist) {
      setCookie(COOKIE_NAME, theme);
    }
  }

  private applyTheme(theme: Theme): void {
    document.documentElement.setAttribute(ATTR_NAME, theme);
  }
}

customElements.define('cms-theme-toggle', CmsThemeToggle);

export { CmsThemeToggle };
