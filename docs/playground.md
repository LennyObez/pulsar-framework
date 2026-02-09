# UI playground

The Pulsar UI Playground is a local development tool for real-time CSS customization and theming. It provides a component catalog, a built-in CSS editor, live preview, and theme file management in a single page with zero external dependencies.

## Starting the playground

```bash
pulsar playground:serve
```

This starts a lightweight dev server on `http://localhost:8942`.

### Options

| Option   | Short | Default     | Description      |
| -------- | ----- | ----------- | ---------------- |
| `--port` | `-p`  | `8942`      | Port to serve on |
| `--host` | `-H`  | `localhost` | Host to bind to  |

```bash
# Custom port
pulsar playground:serve --port 3000

# Bind to all interfaces
pulsar playground:serve --host 0.0.0.0
```

The playground is **disabled in production** (`APP_ENV=production`). Attempting to start it in production outputs an error and exits.

## Layout

The playground is a split-pane interface:

- **Left panel**: CSS editor with syntax highlighting and line numbers
- **Right panel**: Live preview of the component catalog in an iframe

CSS changes in the editor apply instantly to the preview. There is no page reload - styles are injected directly into the iframe via DOM manipulation.

## CSS editor

The editor is custom-built with vanilla HTML/CSS/JS (no external dependencies like Monaco or CodeMirror).

### Features

- Monospace font with line numbers synced to scroll position
- CSS syntax highlighting via a lightweight regex-based token coloring system
- Tab key handling for indentation
- Auto-indentation on Enter key
- Keyboard shortcuts

### Keyboard shortcuts

| Shortcut | Action                           |
| -------- | -------------------------------- |
| `Ctrl+S` | Save current CSS to a theme file |
| `Tab`    | Insert indentation               |
| `Enter`  | New line with auto-indentation   |

## Component catalog

The catalog (loaded in the preview iframe) displays all Pulsar UI elements organized by category:

- **Typography**: h1--h6, paragraphs, lists, blockquotes, code blocks, pre, kbd, mark, abbr
- **Forms**: text input, email, password, number, date, textarea, select, multi-select, checkbox, radio, toggle switch, range slider, file upload, color picker
- **Buttons**: primary, secondary, danger, success, warning, outline, ghost, disabled, loading, sizes (sm/md/lg)
- **Tables**: basic, striped, bordered, hoverable, responsive wrapper
- **Cards**: basic card, card with image, card with footer, card grid
- **Navigation**: navbar, sidebar, breadcrumbs, tabs, pagination, menu
- **Feedback**: alerts (info/success/warning/danger), toasts, progress bars, badges, tooltips
- **Modals/Dialogs**: modal, confirm dialog, drawer/slide-over
- **Layout**: grid demos (2-col, 3-col, 4-col, sidebar+content, holy grail), container, stack, responsive breakpoint demo
- **Media**: image placeholder, avatar, figure with caption
- **Miscellaneous**: accordion, skeleton loaders, empty state

The catalog uses the same CSS classes and component markup as the real Pulsar UI components. Changes made in the playground directly map to production appearance.

## Creating themes

1. Edit CSS in the editor (tokens and component overrides)
2. Press `Ctrl+S` or click the Save button
3. Enter a theme name (alphanumeric and hyphens only)
4. The theme is saved to `resources/themes/{name}.css`

### Theme file format

```css
/* Theme: My Custom Theme */
/* Version: 1.0 */
/* Based on: default */

:root {
  --color-primary-500: #3b82f6;
  --color-secondary-500: #6366f1;
  /* ... custom token overrides ... */
}

/* Component-specific overrides below */
```

Themes override CSS custom properties (design tokens). The default theme defines all tokens; custom themes only need to override the ones they change.

## Managing themes

### Loading a theme

Use the theme dropdown in the editor toolbar to select from available themes. The selected theme CSS is loaded into the editor and applied to the preview.

### Switching themes

One-click theme switching in the dropdown. The preview updates instantly as theme CSS is injected into the iframe. The active theme selection is stored in `ViewConfig::activeTheme` for runtime use.

### Resetting to default

Click "Reset to default" to load the default theme CSS into the editor. The default theme (`resources/themes/default.css`) is read-only and cannot be overwritten.

### Deleting a theme

Custom themes can be deleted via the API. The default theme cannot be deleted.

## Responsive preview

The preview iframe renders the full component catalog. To test responsive behavior, resize the browser window or use browser DevTools responsive mode. The catalog includes responsive grid demos showing layout at different breakpoints.

## API routes

The playground dev server exposes these routes:

| Method   | Route                | Description                  |
| -------- | -------------------- | ---------------------------- |
| `GET`    | `/`                  | Main playground page         |
| `GET`    | `/catalog`           | Component catalog (iframe)   |
| `GET`    | `/api/csrf-token`    | Generate CSRF token          |
| `GET`    | `/api/themes`        | List available themes (JSON) |
| `GET`    | `/api/themes/{name}` | Read theme CSS content       |
| `POST`   | `/api/themes/{name}` | Save theme CSS content       |
| `DELETE` | `/api/themes/{name}` | Delete custom theme          |

Write endpoints (`POST`, `DELETE`) require a valid CSRF token in the `X-CSRF-TOKEN` header. Theme names must be alphanumeric with hyphens only. File writes are restricted to the `resources/themes/` directory (path traversal is prevented).

## Security

- The playground server is **dev-only** and is disabled in production environments
- CSRF protection on all write endpoints
- Theme name validation: alphanumeric and hyphens only
- Path traversal prevention: all file operations are verified to stay within `resources/themes/`
- The default theme is protected from modification and deletion

## Limitations

- The playground is a development tool, not a production feature
- The CSS editor is functional but intentionally minimal (not a full IDE)
- Syntax highlighting is regex-based and covers common CSS patterns
- The playground serves static files via PHP's built-in server (`php -S`) and is not suitable for production traffic
- Theme switching in the playground is instant; applying a theme to the production application requires updating `ViewConfig::activeTheme` in the config
