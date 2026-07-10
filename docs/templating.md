# Template engine

Pulsar ships a compile-to-PHP template engine for trusted templates and a sandboxed AST interpreter for untrusted (user-provided) templates. Both share the same expressive syntax while enforcing strict security boundaries.

## Configuration

Create `config/view.php` to enable the View module:

```php
return [
    'template_paths' => [
        'resources/views',
    ],
    'cache_path' => 'var/cache/views',
    'auto_escape' => true,
    'active_theme' => 'default',
    'php_directive_allowed' => false,
    'sandbox_step_limit' => 10_000,
    'sandbox_loop_limit' => 1_000,
    'sandbox_output_size_limit' => 1_048_576,
    'sandbox_wall_clock_check_interval' => 500,
];
```

### Config fields

| Field                               | Type           | Default     | Description                                                |
| ----------------------------------- | -------------- | ----------- | ---------------------------------------------------------- |
| `template_paths`                    | `list<string>` | `[]`        | Ordered search directories; first match wins               |
| `cache_path`                        | `string`       | `''`        | Directory for compiled PHP cache files (must be writable)  |
| `auto_escape`                       | `bool`         | `true`      | HTML-escape all `{{ }}` output by default                  |
| `active_theme`                      | `string`       | `'default'` | Active theme name (maps to `resources/themes/{name}.css`)  |
| `php_directive_allowed`             | `bool`         | `false`     | Whether `@php` blocks are permitted at compile time        |
| `sandbox_step_limit`                | `int`          | `10000`     | Max AST node evaluations in untrusted mode                 |
| `sandbox_loop_limit`                | `int`          | `1000`      | Max iterations per loop in untrusted mode                  |
| `sandbox_output_size_limit`         | `int`          | `1048576`   | Max rendered output bytes (1 MB) in untrusted mode         |
| `sandbox_wall_clock_check_interval` | `int`          | `500`       | AST steps between wall-clock time checks in untrusted mode |

## Getting started

### Basic rendering

Inject `TemplateEngineInterface` and call `render()`:

```php
use Pulsar\View\Engine\TemplateEngineInterface;

final readonly class DashboardController
{
    public function __construct(
        private TemplateEngineInterface $view,
    ) {}

    public function index(): string
    {
        return $this->view->render('dashboard.index', [
            'title' => 'Dashboard',
            'user' => $currentUser,
        ]);
    }
}
```

Templates use the `.pulse.php` extension. A render call to `'dashboard.index'` resolves to `resources/views/dashboard/index.pulse.php` (dot notation maps to directory separators).

### Template file structure

```
resources/views/
  layouts/
    app.pulse.php
  dashboard/
    index.pulse.php
  partials/
    header.pulse.php
    footer.pulse.php
  components/
    alert.pulse.php
    card.pulse.php
```

### Template name resolution

Template names use dot notation. Each dot becomes a directory separator, and the engine appends `.pulse.php` automatically. The engine searches the directories listed in `template_paths` (from `config/view.php`) in order, returning the first match.

| Template name        | Resolved file path                             |
| -------------------- | ---------------------------------------------- |
| `dashboard.index`    | `resources/views/dashboard/index.pulse.php`    |
| `layouts.app`        | `resources/views/layouts/app.pulse.php`        |
| `theme.layouts.main` | `resources/views/theme/layouts/main.pulse.php` |
| `partials.header`    | `resources/views/partials/header.pulse.php`    |
| `components.card`    | `resources/views/components/card.pulse.php`    |

If a template name contains a namespace prefix with `::` (e.g., `cms::admin.layout`), the prefix is stripped before resolution. Extensions use this convention, but it resolves against the same `template_paths` directories.

To make project templates resolvable, ensure your `config/view.php` includes the project's `resources/views` directory:

```php
return [
    'template_paths' => [
        'resources/views',
    ],
    // ...
];
```

The engine throws a `ViewException` if the template cannot be found in any configured path.

## Template syntax

### Escaped output

All `{{ }}` expressions are HTML-escaped by default using `htmlspecialchars()` with `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8 encoding:

```html
<h1>{{ $title }}</h1>
<p>{{ $user->name }}</p>
<span>{{ strtoupper($status) }}</span>
```

### Raw (unescaped) output

Use `{!! !!}` for trusted, pre-sanitized content. This is only available in trusted templates:

```html
{!! $trustedHtml !!}
```

Raw output is **not available** in untrusted (sandboxed) templates. All output in untrusted mode is auto-escaped with no bypass.

### Comments

Template comments are stripped entirely from compiled output:

```html
{{-- This comment will not appear in the rendered HTML --}}
```

## Directives reference

### Control flow

#### @if / @elseif / @else / @endif

```html
@if($users->count() > 0)
<p>{{ $users->count() }} users found.</p>
@elseif($showEmpty)
<p>No users yet.</p>
@else
<p>Access denied.</p>
@endif
```

#### @foreach / @endforeach

```html
<ul>
  @foreach($items as $item)
  <li>{{ $item->name }}</li>
  @endforeach
</ul>
```

#### @for / @endfor

```html
@for($i = 0; $i < 10; $i++)
<span>{{ $i }}</span>
@endfor
```

#### @while / @endwhile

```html
@while($condition)
<p>Still processing...</p>
@endwhile
```

#### @switch / @case / @default / @endswitch

```html
@switch($role) @case('admin')
<span class="badge badge-danger">Admin</span>
@case('editor')
<span class="badge badge-warning">Editor</span>
@default
<span class="badge badge-info">User</span>
@endswitch
```

### Template inheritance

#### @extends / @section / @yield

Define a layout with `@yield` placeholders:

```html
{{-- layouts/app.pulse.php --}}
<!doctype html>
<html lang="en">
  <head>
    <title>@yield('title', 'Pulsar App')</title>
  </head>
  <body>
    <main>@yield('content')</main>
    <footer>
      @yield('footer', '
      <p>Default footer</p>
      ')
    </footer>
  </body>
</html>
```

Extend the layout and fill sections:

```html
{{-- dashboard/index.pulse.php --}} @extends('layouts.app') @section('title') Dashboard @endsection
@section('content')
<h1>Welcome, {{ $user->name }}</h1>
<p>You have {{ $notifications }} new notifications.</p>
@endsection
```

The `@yield` directive accepts an optional second argument as a default value when no section is provided.

### Partials

#### @include

Include another template inline with optional scoped data:

```html
@include('partials.header')

<div class="content">@include('partials.user-card', ['user' => $currentUser])</div>

@include('partials.footer')
```

### Components and slots

#### @component / @slot / @endcomponent / @endslot

Define reusable components with named slots:

```html
{{-- components/card.pulse.php --}}
<div class="card">
  <div class="card-header">@yield('header')</div>
  <div class="card-body">@yield('body')</div>
</div>
```

Use the component with slot content:

```html
@component('components.card') @slot('header')
<h3>{{ $title }}</h3>
@endslot @slot('body')
<p>{{ $description }}</p>
@endslot @endcomponent
```

Content outside named slots becomes the default slot.

### Authentication directives

#### @auth / @endauth

Render content only for authenticated users:

```html
@auth
<p>Welcome back, {{ $user->name }}!</p>
@endauth
```

Optionally specify a guard:

```html
@auth('admin')
<a href="/admin">Admin Panel</a>
@endauth
```

#### @guest / @endguest

Render content only for unauthenticated users:

```html
@guest
<a href="/login">Sign In</a>
@endguest
```

#### @can / @endcan

Render content based on authorization:

```html
@can('edit', $post)
<a href="/posts/{{ $post->id }}/edit">Edit</a>
@endcan
```

### Form directives

#### @csrf

Outputs a hidden input with the CSRF token:

```html
<form method="POST" action="/submit">
  @csrf
  <input type="text" name="title" />
  <button type="submit">Submit</button>
</form>
```

Renders: `<input type="hidden" name="_token" value="...">` (value is HTML-escaped).

#### @method

Outputs a hidden input for HTTP method spoofing:

```html
<form method="POST" action="/posts/{{ $post->id }}">
  @csrf @method('DELETE')
  <button type="submit">Delete</button>
</form>
```

Renders: `<input type="hidden" name="_method" value="DELETE">`.

### Internationalization

#### @t (preferred) / @i18n

Outputs a translated string (HTML-escaped). Use `@t()` as the primary translation directive:

```html
<h1>@t('messages.welcome')</h1>
<p>@t('messages.greeting', ['name' => $user->name])</p>
```

`@i18n()` is an alias that works identically but `@t()` is the recommended form used throughout the framework's templates and extensions. Both integrate with the `__()` translation helper from the i18n module.

#### Translation key format

Translation keys use dot notation for nesting. The dots represent nested array keys in your language files, not translation domains:

```html
@t('navigation.home') {{-- Key: navigation.home --}} @t('errors.validation.required') {{-- Key:
errors.validation.required --}}
```

These keys correspond to nested arrays in your language files:

```php
// resources/lang/en/messages.php
return [
    'navigation' => [
        'home' => 'Home',
        'about' => 'About',
    ],
    'errors' => [
        'validation' => [
            'required' => 'This field is required.',
        ],
    ],
];
```

#### Translation domains

The `@t()` directive uses the default `messages` domain. To use a different domain, pass it as the fourth argument to the `__()` helper in PHP code or use the translator directly in a controller:

```php
// In a controller, using the __() helper with an explicit domain:
$label = __('field.name', [], null, 'forms');
```

Domains map to separate language files. The `messages` domain loads from `resources/lang/{locale}/messages.php`, while a `forms` domain loads from `resources/lang/{locale}/forms.php`.

#### Passing parameters

Use the second argument to pass interpolation parameters:

```html
@t('greeting', ['name' => $user->name])
```

Parameters are formatted using ICU MessageFormat when the message formatter is configured, or simple `{name}` replacement otherwise.

### Inline PHP

#### @php / @endphp

Execute arbitrary PHP within a template:

```html
@php $total = array_sum(array_column($items, 'price')); @endphp

<p>Total: {{ number_format($total, 2) }}</p>
```

**Policy**: `@php` is **disabled by default** in production. When `ViewConfig::phpDirectiveAllowed` is `false`, the compiler rejects `@php` blocks at compile time with a clear error. When enabled and used, a compliance-grade audit event is emitted via `AuditLoggerInterface` with the template ID, file hash, actor identity, and correlation ID.

## Context-aware escaping

All `{{ }}` output is HTML-escaped by default. For other contexts, use the dedicated escape helpers:

| Helper               | Context        | Description                                              |
| -------------------- | -------------- | -------------------------------------------------------- |
| `{{ $value }}`       | HTML (default) | `htmlspecialchars()` with `ENT_QUOTES \| ENT_SUBSTITUTE` |
| `{{ url($value) }}`  | URL            | URL-encodes; blocks `javascript:`, `data:`, `vbscript:`  |
| `{{ attr($value) }}` | HTML attribute | Encodes non-alphanumeric chars as numeric HTML entities  |
| `{{ js($value) }}`   | JavaScript     | JSON-encodes with HTML-safe flags for inline `<script>`  |
| `{{ css($value) }}`  | CSS            | Encodes non-alphanumeric chars as CSS hex escapes        |
| `{!! $value !!}`     | Raw            | No escaping; trusted content only                        |

### Examples

```html
{{-- URL context: safe for href/src attributes --}}
<a href="{{ url($profileUrl) }}">Profile</a>

{{-- Attribute context: safe inside quoted attribute values --}}
<div data-name="{{ attr($userName) }}">
  {{-- JavaScript context: safe in inline script blocks --}}
  <script>
    var config = {{ js($configJson) }};
  </script>

  {{-- CSS context: safe in inline styles --}}
  <div style="color: {{ css($userColor) }};"></div>
</div>
```

The `url()` helper actively blocks dangerous URI schemes (`javascript:`, `data:`, `vbscript:`) by normalizing away invisible Unicode characters before checking, preventing bypass attempts.

## Shared data and view composers

Partials such as the site header and footer need global data (localized navigation, current locale, section bar) on every page — including pages the framework renders itself, such as the themed `errors/404` and `errors/5xx` pages, where no controller runs. Instead of threading that data through every controller, register it once on the engine; it is merged into **every** render the engine performs, framework-internal renders included.

### Shared data

```php
$engine = $container->get(TemplateEngineInterface::class);

$engine->share('siteName', 'Acme');             // single key
$engine->share(['locale' => 'fr', 'tz' => 'UTC']); // bulk
```

Shared data is **request-scoped**: it never leaks across requests or Fibers (persistent-worker runtimes reset it between requests automatically). For application-lifetime constants, prefer a wildcard composer.

### View composers

A composer is a callable invoked **lazily** for renders whose template name matches a glob pattern. It contributes data by returning an array or via `ViewContext::with()`:

```php
$engine->composer('theme.partials.*', function (ViewContext $ctx): array {
    return ['nav' => $this->navBuilder->build($ctx->get('locale', 'en'))];
});

$engine->composer(['errors.*', '*'], ...);  // multiple patterns / match everything
```

Guarantees:

- **Lazy** — a composer runs only when a matching template actually renders, and **at most once per request** (its output is memoized), so an expensive nav tree is built once even when header, footer and drawer all match. Pattern matching itself runs on every render; only the composer's execution is memoized.
- **Deterministic precedence**, low to high: shared data → composer output (registration order; later overrides earlier) → the explicit `render()` data. Explicit data always wins.
- **Nested includes inherit, and inherited data wins** — an `@include` partial receives the parent render's resolved data as its explicit data. On a key collision the inherited value therefore wins: a partial-matching composer cannot override a key the parent already resolved (e.g. from a `'*'` composer); it can only add keys the parent did not provide.
- **No hot-path cost** when nothing is registered: `render()` short-circuits.

### Error pages get the chrome for free

`ErrorPageRenderer` resolves the same configured engine, so with the composer above registered, a routing miss under `APP_DEBUG=false` renders the themed `errors/404` with the real `@include('theme.partials.header')` chrome — zero controller involvement, no undefined-variable fallback.

### Where to register

Register shares/composers once at boot, after the engine is bound and before the first render — an extension's boot hook (ADR-0004) is the canonical place:

```php
public function boot(ContainerInterface $container): void
{
    $engine = $container->get(TemplateEngineInterface::class);
    $engine->composer('theme.partials.*', new NavComposer($container->get(NavBuilder::class)));
}
```

## Trusted vs untrusted templates

Pulsar enforces a strict separation between trusted and untrusted templates with fundamentally different execution models.

### Trusted templates

Trusted templates are shipped with the application and written by developers:

- **Compiled to PHP** for maximum performance
- Full directive set available (including `@php` if enabled)
- Cached as compiled PHP files in the configured cache directory
- Standard code review provides security assurance

```php
use Pulsar\View\Engine\TemplateEngineInterface;

// Trusted rendering - compile-to-PHP
$html = $engine->render('dashboard.index', ['user' => $user]);
```

### Untrusted templates

Untrusted templates are user-provided, uploaded, or stored in a database:

- **Never compiled to PHP** under any circumstances
- Executed via a restricted AST interpreter that walks the parsed template tree
- Restricted directive set: `@if`, `@foreach`, `@include` (allowlisted template IDs only), `@i18n`, and variable interpolation
- No `@php`, no raw output `{!! !!}`, no file system access
- Deterministic resource bounds prevent runaway execution

```php
use Pulsar\View\Sandbox\SandboxConfig;
use Pulsar\View\Sandbox\SandboxEngine;

$config = new SandboxConfig(
    stepLimit: 10_000,
    loopLimit: 1_000,
    outputSizeLimit: 1_048_576,
    wallClockCheckInterval: 500,
    wallClockLimitSeconds: 5.0,
    includeAllowlist: [
        'header' => '<header>{{ $siteName }}</header>',
        'footer' => '<footer>Copyright {{ $year }}</footer>',
    ],
);

$sandbox = new SandboxEngine($config);
$html = $sandbox->render($userTemplate, ['siteName' => 'Acme', 'year' => '2026']);
```

### Sandbox resource bounds

| Bound             | Default   | Description                                  |
| ----------------- | --------- | -------------------------------------------- |
| Step limit        | 10,000    | Max AST node evaluations per render          |
| Loop limit        | 1,000     | Max iterations per individual loop construct |
| Output size limit | 1 MB      | Max total bytes of rendered output           |
| Wall-clock check  | Every 500 | Steps between wall-clock time checks         |
| Wall-clock limit  | 5.0s      | Maximum wall-clock execution time            |

All limits produce deterministic failure modes with specific error messages (not silent truncation). Exceeding any bound throws a `ViewException`.

### Sandbox include allowlist

In untrusted mode, `@include` accepts **template IDs** from a preconfigured allowlist, not file paths. This prevents path traversal and data exfiltration:

```php
$config = new SandboxConfig(
    includeAllowlist: [
        'company-header' => '<header class="brand">{{ $companyName }}</header>',
        'legal-footer' => '<footer>{{ $legalText }}</footer>',
    ],
);
```

Template IDs are registered in the sandbox configuration and map to known, pre-validated template content.

## Build-time compilation

### view:compile command

Pre-compile all templates during the build/deploy step so that no runtime compilation is needed in production:

```bash
pulsar view:compile
```

Options:

| Option    | Short | Description                            |
| --------- | ----- | -------------------------------------- |
| `--force` | `-f`  | Recompile all templates even if cached |

The command scans all configured `template_paths` for `.pulse.php` files, compiles them, and writes the artifacts to the `cache_path` directory. Deploy this directory as a build artifact.

Output example:

```
Compiling templates...
Compilation complete: 47 compiled, 0 skipped, 0 errors in 0.182s
All 47 template(s) are compiled and cached at: var/cache/views
```

### Development mode

In development, the engine automatically recompiles templates when the source file changes. Cache invalidation is based on file modification time and content hash (SHA-256). Same source input always produces identical compiled output (no timestamps or non-deterministic elements).

### Production deployment

1. Run `pulsar view:compile` during the build step
2. Deploy the `var/cache/views/` directory alongside your application
3. Set `php_directive_allowed` to `false` in production config
4. The engine uses cached artifacts with no runtime compilation

## Performance

- Template compilation target: < 5ms per template (trusted path)
- Compiled templates execute as native PHP with `include` (zero overhead)
- Content-hash-based cache validation avoids unnecessary recompilation
- Sandbox AST interpretation has measurable overhead but enforces strict security bounds

## Security considerations

### XSS prevention

- All `{{ }}` output is HTML-escaped by default
- Context-aware helpers (`url()`, `attr()`, `js()`, `css()`) prevent injection in non-HTML contexts
- The `url()` helper blocks `javascript:`, `data:`, and `vbscript:` URI schemes after normalizing invisible Unicode characters
- Raw output `{!! !!}` is an explicit opt-in for trusted content only

### Untrusted template isolation

- Untrusted templates are **never compiled to PHP** and cannot execute arbitrary code
- Only a restricted subset of directives is available in sandbox mode
- `@include` uses template IDs from an allowlist, not file paths
- Resource bounds prevent denial-of-service via runaway templates
- All output is auto-escaped with no bypass in untrusted mode

### @php audit trail

When `@php` is enabled (non-production environments), each usage emits a compliance-grade audit event via `AuditLoggerInterface` containing:

- Template ID
- File content hash
- Actor / build identity
- Correlation ID

This is designed for SOC 2, HIPAA, and similar audit trail requirements.
