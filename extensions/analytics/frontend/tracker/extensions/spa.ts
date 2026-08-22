/**
 * SPA navigation tracker extension.
 *
 * Detects client-side navigation in single-page applications by wrapping
 * `history.pushState` and `history.replaceState`. This monkey-patching
 * approach is necessary because SPAs do not trigger full page loads, so
 * the standard pageview beacon would only fire once. The wrappers call
 * through to the original methods (bound via `.bind(history)` to preserve
 * the correct `this` context) and then emit a synthetic pageview event.
 *
 * A 100ms debounce prevents duplicate events when frameworks call both
 * pushState and replaceState in rapid succession (e.g., Next.js shallow
 * routing). The `popstate` listener covers browser back/forward buttons.
 */
window.plsr.ext((send: PlsrSendFn, site: string) => {
  let prev = location.href;
  let timer: ReturnType<typeof setTimeout> | null = null;

  const fire = (): void => {
    if (location.href === prev) return;
    prev = location.href;
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      send({
        type: 'pageview',
        site,
        url: location.href,
        referrer: document.referrer,
        screen_width: screen.width,
      });
    }, 100);
  };

  const orig = history.pushState.bind(history);
  history.pushState = function (...args: Parameters<typeof history.pushState>): void {
    orig(...args);
    fire();
  };

  const origR = history.replaceState.bind(history);
  history.replaceState = function (...args: Parameters<typeof history.replaceState>): void {
    origR(...args);
    fire();
  };

  window.addEventListener('popstate', fire);
});
