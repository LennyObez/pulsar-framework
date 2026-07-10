# Asset serving

How to serve CSS, JavaScript, images, and other static files from a Pulsar project.

## Static assets in public/

The `public/` directory is the web root. Any file placed here is served directly by the web server (Nginx, Apache, Caddy, or the built-in dev server). No framework routing or PHP execution is involved.

```
public/
  index.php        # Front controller (the only PHP file the web server executes)
  favicon.ico
  robots.txt
  assets/
    css/
      app.css
    js/
      app.js
    images/
      logo.svg
```

For production, point your web server's document root to `public/` and configure it to serve static files directly, falling back to `index.php` for everything else.

### Nginx example

```nginx
server {
    root /var/www/my-app/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Cache static assets aggressively
    location ~* \.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

## Resource files

Project-level templates, CSS source files, and language files live in `resources/`:

```
resources/
  views/         # Pulse templates (.pulse.php)
  css/           # CSS source files
  lang/          # Translation files
```

These files are not directly accessible from the browser. To make them available, you have two options.

## Option A: Publish with asset:publish (recommended)

The `asset:publish` command creates symlinks from `resources/` subdirectories into `public/assets/`, making them accessible to the web server without duplication.

```bash
pulsar asset:publish
```

This creates:

```
public/assets/
  views/ -> ../../resources/views/
  css/   -> ../../resources/css/
  lang/  -> ../../resources/lang/
```

### Options

| Flag      | Short | Description                             |
| --------- | ----- | --------------------------------------- |
| `--force` | `-f`  | Overwrite existing symlinks             |
| `--copy`  | `-c`  | Copy files instead of creating symlinks |

Use `--copy` in environments where symlinks are not supported (some shared hosting, certain container setups).

### Re-publishing after changes

In development, symlinks point to the live source files, so changes are reflected immediately. If you used `--copy`, run the command again after editing resource files.

For production deployments, run `asset:publish` as part of your build pipeline:

```bash
pulsar asset:publish --copy
```

## Option B: Serve via a framework route

For dynamic asset serving (e.g., theme CSS that depends on configuration), you can register a route that reads and serves the file:

```php
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

$router->get('/assets/theme.css', function (ServerRequest $request): Response {
    $css = file_get_contents(resource_path('css/theme/main.css'));
    return Response::create($css, 200, [
        'Content-Type' => 'text/css; charset=UTF-8',
        'Cache-Control' => 'public, max-age=86400',
    ]);
});
```

This approach adds PHP overhead per request. Use it only when you need runtime logic (e.g., token replacement, conditional includes). For static files, always prefer Option A.

## Theme CSS

Theme stylesheets typically live in `resources/css/theme/`. After publishing, they are available at `/assets/css/theme/main.css`.

In your layout template:

```html
<link rel="stylesheet" href="/assets/css/theme/main.css" />
```

## Progressive enhancement without inline scripts

The strongest, most cacheable script policy is `script-src 'self'` — no inline,
no hash, no nonce. The one thing that classically blocks it is the inline
"flip the page into a JS-enabled state before first paint" bootstrap
(`<script>document.documentElement.classList.add('js')</script>`), which forces
a `script-src 'sha256-…'` into every response.

Pulsar ships that mechanism as **CSS + an external script**, so no inline code
is needed. Reveal-on-scroll elements are marked `data-pulsar-reveal`:

```html
<head>
  <link rel="stylesheet" href="/ui/css/progressive-enhancement.css" />
</head>
<body>
  <section data-pulsar-reveal>…</section>
  <script src="/ui/js/reveal.js" defer></script>
</body>
```

- `progressive-enhancement.css` hides `[data-pulsar-reveal]` **only** inside
  `@media (scripting: enabled)`, which matches from first paint when JS is on —
  so there is no flash, and no `js` / `no-js` class is needed.
- With JS off (or the `scripting` feature unsupported) the elements keep their
  default visible state, so the no-JS experience is correct.
- `reveal.js` (served same-origin at `/ui/js/reveal.js`) adds `.is-revealed` via
  an `IntersectionObserver`. If it never loads, a CSS fail-safe animation reveals
  the content anyway — nothing is ever permanently hidden.

> **Why no `<noscript>` stylesheet?** Because the hiding rule lives _inside_
> `@media (scripting: enabled)`, an engine that does not support the `scripting`
> feature simply never matches it and leaves the elements visible — so there is
> no state to correct. Scoping the hide inside the media query makes the classic
> `<noscript><link rel="stylesheet">` fallback unnecessary, which is the stronger
> result: one fewer request and no failure mode where content is hidden with no
> way back.

Because none of this is inline, the recommended default CSP is simply:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'
```

Hashes and nonces become **opt-in**, only for hosts that deliberately add their
own inline scripts. See [security-baseline.md](security-baseline.md).

### Error pages and the last-resort exception

The framework's own shipped output honours this policy. The styled error pages
render through Pulse templates that link `/ui/css/errors.css` — no inline
`<script>` or `<style>`. The retry countdown (429/503) and the cookie-consent
banner are external, same-origin scripts.

The one deliberate exception is the **self-contained fallback renderers**
(`ProductionRenderer` and the `ErrorPageRenderer` / `ExceptionHandler` inline
fallbacks): they render precisely when the template engine or the asset routes
are unavailable, so they keep a few lines of inline CSS. Under a strict
`style-src 'self'` that inline CSS is simply dropped and the page degrades to
readable semantic HTML; when the failure is severe enough that no CSP header is
emitted either, the inline CSS applies and the page is styled. Either way the
fallback stays legible — which is the whole point of a last-resort renderer. The
generated `welcome.php` scaffold is likewise a self-contained placeholder you
replace immediately.

## Helper functions

Pulsar provides path helpers for building asset references in PHP code:

```php
// Absolute filesystem paths
public_path('assets/css/app.css');      // /var/www/my-app/public/assets/css/app.css
resource_path('css/theme/main.css');    // /var/www/my-app/resources/css/theme/main.css
storage_path('uploads/avatar.jpg');     // /var/www/my-app/storage/uploads/avatar.jpg
```

For generating URLs in templates, use the path relative to the web root:

```html
<link rel="stylesheet" href="/assets/css/app.css" />
<script src="/assets/js/app.js"></script>
```
