# Pulsar Admin Console

Native HTML5 + ES2025 + Web Components admin SPA per Decision 2.8.

**Stack** : Web Components v1, Shadow DOM, JSDoc + `tsc --checkJs`, hand-rolled signals + router primitives, design tokens via CSS custom properties, modern CSS (cascade layers, container queries, `:has()`, OKLCH). Zero framework dependency, zero compiler, zero bundler required (optional `esbuild --minify` for production).

**Tests** : WebdriverIO 9+ multi-browser (Chromium, Firefox, WebKit) per Decision 2.47.

**Build** : files served as written by `pulsar-console-api`. Production minification optional via `pnpm build`.

## Layout

```
src/
├── main.js                  bootstrap entry
├── lib/                     primitives (signals, router, api-client, store, form, focus)
├── design-system/           tokens.css, utilities.css, base.css
├── components/              Web Components self-registering on import
└── pages/                   route-bound page compositions
tests/                       WebdriverIO E2E tests
```

See [../../docs/plan.md](../../docs/plan.md) Section V Sprint 3.1 for the full implementation plan.

## Licence

Apache-2.0. See the workspace [LICENSE](../../LICENSE) file.
