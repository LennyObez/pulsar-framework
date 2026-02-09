window.plsr.ext(() => {
  const exts = /\.(pdf|zip|docx?|xlsx?|pptx?|tar|gz|rar|7z|csv|mp[34])$/i;

  document.addEventListener("click", (e: MouseEvent) => {
    const link = (e.target as HTMLElement).closest("a");
    if (!link) return;

    const href = link.href;
    if (href && exts.test(href)) {
      window.plsr.event("File Download", { url: href });
    }
  });
});
