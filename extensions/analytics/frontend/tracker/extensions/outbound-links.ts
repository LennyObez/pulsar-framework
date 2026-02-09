window.plsr.ext(() => {
  document.addEventListener("click", (e: MouseEvent) => {
    const link = (e.target as HTMLElement).closest("a");
    if (!link) return;

    const href = link.href;
    if (!href) return;

    try {
      const url = new URL(href);
      if (url.hostname === location.hostname) return;

      window.plsr.event("Outbound Link: Click", { url: href });

      if (!link.target || link.target === "_self") {
        e.preventDefault();
        setTimeout(() => {
          location.href = href;
        }, 150);
      }
    } catch (_) {
      // invalid URL, skip
    }
  });
});
