(function () {
  try {
    if (navigator.doNotTrack === '1') return;

    const s = document.currentScript as HTMLScriptElement | null;
    if (!s) return;

    const id = s.getAttribute('data-site');
    const api = s.getAttribute('data-api');
    if (!id || !api) return;

    const send: PlsrSendFn = (p: PlsrPayload): void => {
      try {
        const body = JSON.stringify(p);
        if (navigator.sendBeacon) {
          navigator.sendBeacon(api, body);
        } else {
          fetch(api, {
            method: 'POST',
            body,
            headers: { 'Content-Type': 'application/json' },
            keepalive: true,
          });
        }
      } catch {
        // silent
      }
    };

    const pv = (): void => {
      send({
        type: 'pageview',
        site: id,
        url: location.href,
        referrer: document.referrer,
        screen_width: screen.width,
      });
    };

    window.plsr = {
      event(
        name: string,
        props?: Record<string, string | number | boolean>,
        revenue?: number,
      ): void {
        const p: PlsrPayload = {
          type: 'event',
          site: id,
          event_name: name,
          url: location.href,
        };
        if (props) p.event_props = props;
        if (revenue !== undefined) p.revenue_value = revenue;
        send(p);
      },
      ext(fn: (send: PlsrSendFn, site: string) => void): void {
        try {
          fn(send, id);
        } catch {
          // silent
        }
      },
    };

    pv();

    const ext = s.getAttribute('data-extensions');
    if (ext) {
      const base = s.src.substring(0, s.src.lastIndexOf('/') + 1);
      ext.split(',').forEach((name: string) => {
        const n = name.trim();
        if (n) {
          const el = document.createElement('script');
          el.async = true;
          el.src = base + 'extensions/' + n + '.js';
          document.head.appendChild(el);
        }
      });
    }
  } catch {
    // silent
  }
})();
