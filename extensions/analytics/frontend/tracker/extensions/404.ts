window.plsr.ext(() => {
  if (
    document.querySelector("[data-plsr-404]") ||
    document.title.includes("404")
  ) {
    window.plsr.event("404", { path: location.pathname });
  }
});
