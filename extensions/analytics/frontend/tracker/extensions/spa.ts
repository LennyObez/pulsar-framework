window.plsr.ext((send, site) => {
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
