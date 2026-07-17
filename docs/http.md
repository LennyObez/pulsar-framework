# HTTP layer

Pulsar provides immutable value objects for HTTP requests and responses. These are not PSR-7 implementations - they are purpose-built for Pulsar's architecture with a focus on simplicity, immutability, and type safety.

## PSR-7 non-adoption rationale

Pulsar uses its own HTTP abstractions because:

1. **Simpler API** - PSR-7's `StreamInterface` and message factories add complexity rarely needed in framework internals.
2. **Readonly by design** - PHP 8.2 `readonly class` provides compile-time immutability guarantees that PSR-7 can only enforce at runtime.
3. **Reduced dependency surface** - No external packages for HTTP message handling.
4. **Enum-backed types** - `Method` and `ResponseStatus` are backed enums with domain methods, not string/int constants.

For interoperability with PSR-7/PSR-15 libraries, Pulsar ships a first-party bridge extension at `extensions/psr7-bridge/` (`pulsar/psr7-bridge`). It provides bidirectional adapters between Pulsar's HTTP objects and PSR-7 interfaces, plus a PSR-15 middleware adapter. See [ADR-0003](adr/0003-non-psr7-http-abstractions.md) for the design rationale.

## Request

Controllers should type-hint `Pulsar\Http\Message\ServerRequest` (the concrete class), **not** the PSR-7 `Psr\Http\Message\ServerRequestInterface`. The concrete class provides Pulsar-specific convenience methods (`json()`, `query()`, `post()`, `input()`, `all()`, `wantsJson()`, etc.) that are not part of the PSR-7 interface.

```php
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Response;

class UserController
{
    public function show(ServerRequest $request, string $id): Response
    {
        $page = $request->query('page', '1');
        $data = $request->json();
        // ...
    }
}
```

If you type-hint `ServerRequestInterface`, you lose access to Pulsar helpers and must use PSR-7 methods (`getQueryParams()`, `getParsedBody()`, etc.) directly.

### Parameter access

```php
$request->query('page', '1');    // GET parameter with default
$request->post('username');       // POST parameter
$request->cookie('session');      // Cookie value
$request->server('REMOTE_ADDR'); // Server variable
$request->attribute('user_id');  // Custom attribute
$request->header('Accept');      // Header value
```

### Unified input

The `all()` method merges all input sources with precedence: **JSON body > POST > query**.

```php
$all = $request->all();              // Merged input
$name = $request->input('name');     // Lookup from merged input
$request->has('name', 'email');      // All keys exist?
$request->filled('name');            // Key exists and not empty?
$request->only('name', 'email');     // Subset of merged input
$request->except('_token');          // Merged input minus keys
```

### JSON body parsing

```php
$data = $request->json();  // Decoded JSON body (empty array on failure)
```

Returns the decoded JSON body when `Content-Type` contains `application/json`. Returns `[]` on:

- Wrong or missing content type
- Empty body
- Invalid JSON

### Content negotiation

```php
$request->wantsJson();            // Accept header contains application/json
$request->preferredContentType(); // First type from Accept header
$request->isAjax();               // X-Requested-With: XMLHttpRequest
$request->isSecure();             // Request over HTTPS
```

### Response compression

`CompressionMiddleware` negotiates `Accept-Encoding` (honouring q-values, and `q=0` as an RFC 9110 refusal) and offers only codecs it can actually produce: `br` and `zstd` appear when their extensions are loaded, `gzip`/`deflate` always. Responses under 256 bytes, already-encoded responses, and already-compressed content types (images, video, fonts, archives) are passed through untouched, as is any result that failed to shrink.

Server preference is `br > zstd > gzip > deflate`. Brotli leads because it carries a built-in dictionary of common web strings, which is worth the most on exactly what a dynamic response is — small text — and because every browser supports it, while zstd is absent from Safari.

The codec levels are the load-bearing part, and the defaults are deliberate:

| Setting         | Default | Why                                                                        |
| --------------- | ------- | -------------------------------------------------------------------------- |
| `gzipLevel`     | 5       | Level 9 costs ~2x the CPU for ~1% fewer bytes.                             |
| `brotliQuality` | 5       | **Not** the extension's default of 11. See below.                          |
| `zstdLevel`     | 3       | The extension's default, restated explicitly so it is visible and tunable. |

`brotli_compress()` defaults to quality 11, which exists to compress a static asset once at build time and serve it a million times. On a request path it is unusable: measured on PHP 8.5 with libbrotli, q11 costs **105 ms of CPU on a 51 KB page** and 21 ms on a 9 KB page — roughly 86x gzip-5 — to save 16-20% of bytes. Since `br` leads server preference and every browser offers it, omitting the quality argument silently opts every dynamic response into that.

Quality 5 is the measured optimum for dynamic text: better ratio than gzip-5 (-7.8% on a 9 KB page) at comparable cost, and it dominates its neighbours — q4 is both slower and larger, q6 is 1.8x slower for 0.2% fewer bytes. Raise `brotliQuality` only for pre-compressed static assets served from disk, never for rendered responses.

```php
$middleware = new CompressionMiddleware(
    minimumBytes: 256,
    gzipLevel: 5,
    brotliQuality: 5,
    zstdLevel: 3,
);
```

Both optional codecs are installed and asserted in CI (`tools/ci/assert-extensions.php`), so these paths are exercised against real extensions rather than self-skipping into a false green.

### Immutability

Requests are readonly. Use `with*` methods to derive new instances:

```php
$new = $request->withAttribute('user_id', 42);
$new = $request->withoutAttribute('user_id');
```

### Factory

```php
$request = Request::fromGlobals();  // Create from PHP superglobals
```

## Response

`Pulsar\Http\Response` is a `readonly class` representing an outgoing HTTP response.

### Factory methods

```php
Response::json($data, $status);              // JSON with Content-Type
Response::html($html, $status);              // HTML with Content-Type
Response::text($text, $status);              // Plain text
Response::redirect($url, $status);           // Location header redirect
Response::noContent();                        // 204 No Content
Response::validationError($violations);       // 422 JSON validation error
```

### Template rendering

`Response::view()` renders a Pulse template into an HTML response. It accepts either an explicit `TemplateEngineInterface` instance or uses the static engine configured during kernel boot (via `ViewWiring`).

```php
// Using the static engine (most common in controllers)
return Response::view('pages.about', ['title' => 'About Us']);

// With a custom status code
return Response::view('errors.not-found', ['message' => 'Page missing'], 404);

// Explicit engine injection (for tests or non-standard setups)
return Response::view($engine, 'pages.about', ['title' => 'About Us'], 200);
```

If no template engine has been configured and you call `Response::view()` with a string template name, a `RuntimeException` is thrown. Register `ViewWiring` during bootstrap or call `Response::setTemplateEngine()` manually.

### Validation error response

```php
$response = Response::validationError([
    ['field' => 'email', 'message' => 'Required.', 'rule' => 'required'],
]);
```

Produces:

```json
{
  "error": "Validation Failed",
  "status": 422,
  "violations": [{ "field": "email", "message": "Required.", "rule": "required" }]
}
```

### Immutability

```php
$new = $response->withBody('new body');
$new = $response->withStatus(ResponseStatus::NotFound);
$new = $response->withHeader('X-Custom', 'value');
$new = $response->withAddedHeader('Accept', 'text/html');
$new = $response->withoutHeader('X-Remove');
$new = $response->withProtocolVersion('2.0');
```

### Inspection

```php
$response->isEmpty();       // Body is empty string
$response->contentLength(); // Content-Length header as int, or null
$response->contentType();   // Content-Type header value, or null
```

## HeaderBag

`Pulsar\Http\HeaderBag` is a `readonly class` for case-insensitive HTTP header storage (RFC 7230).

```php
$headers = new HeaderBag(['Content-Type' => 'application/json']);
$value = $headers->first('content-type');  // Case-insensitive lookup
$all = $headers->get('Accept');            // All values as list
$headers->has('Authorization');            // Check existence
```

## Route constraints

Routes support per-parameter regex constraints that restrict which values match a parameter:

```php
$router->add(new Route(
    methods: [Method::GET],
    path: '/users/{id}',
    handler: ShowUserHandler::class,
    constraints: ['id' => '\d+'],
));
```

When a constraint is set, the parameter must match the regex for the route to match. Unconstrained parameters default to `[^/]+` (any non-slash characters).

### Multiple constraints

```php
$router->add(new Route(
    methods: [Method::GET],
    path: '/users/{id}/posts/{slug}',
    handler: ShowPostHandler::class,
    constraints: ['id' => '\d+', 'slug' => '[a-z0-9\-]+'],
));
```

### Fallback routes

Constraints enable layered routing where a constrained route is tried first, falling through to less-specific routes:

```php
// Matches /users/123 (numeric IDs only)
$router->add(new Route(
    methods: [Method::GET],
    path: '/users/{id}',
    handler: ShowUserByIdHandler::class,
    constraints: ['id' => '\d+'],
));

// Matches /users/john (any string - fallback)
$router->add(new Route(
    methods: [Method::GET],
    path: '/users/{slug}',
    handler: ShowUserBySlugHandler::class,
));
```

## Route precedence and collisions

Routes are registered in a fixed order: framework wirings first, then the
project's `routes/web.php` and `routes/api.php`, then enabled extensions.
Registration is first-registered-wins for both static and dynamic routes, keyed
by method, path, and host. The effective precedence is therefore framework >
project > extension, so an application route always wins over an extension route
that declares the same method and path.

When a later route claims an already-registered key with a different handler, it
is recorded as a collision and excluded from matching rather than silently
overriding the winner. At boot the framework logs a warning for each collision
in production and fails closed (throws) when `app.debug` is true, so a shadowed
route cannot ship unnoticed. Re-registering the exact same route (identical
handler and name), which happens when a non-strict route cache is replayed, is a
benign duplicate and is ignored. The recorded collisions are available on
`Router::$collisions` for diagnostics, and both routes remain visible to
`route:list`.

See [ADR-0034](adr/0034-route-registration-precedence.md) for the full rationale.

## Path canonicalization

`Route::matchesPath()` trims leading and trailing slashes before comparing, so a
single registered route also answers an unbounded family of spellings — `/x`,
`/x/`, `//x`, and `///x///` all match and return `200`. That is convenient but
produces duplicate content: every crawlable URL exists under infinitely many
addresses, splitting crawl budget and link equity across them, and it compounds
with locale prefixes (`/nl//coaching` leaks a stray `//coaching` downstream).

Enable canonicalization to collapse those spellings to one. In
`config/routing.php`:

```php
return [
    'redirect_to_canonical_path' => true,
];
```

or set `PULSAR_ROUTING_REDIRECT_TO_CANONICAL_PATH=true`. When on, a request
whose path is not already canonical (repeated slashes collapsed, trailing slash
dropped) is redirected to the canonical spelling **before** routing runs:

- **`GET`/`HEAD`** redirect with `301 Moved Permanently` — cacheable, the signal
  crawlers honour.
- **Other methods** redirect with `308 Permanent Redirect`, which preserves the
  method and body, so a `POST` to a non-canonical path is not silently
  downgraded to a `GET`.
- The **query string is carried over** unchanged.
- The **root `/` is exempt** (it is canonical by definition, so there is no
  redirect loop).
- The middleware runs **outermost**, before the locale-prefix strip, so
  `/nl//coaching` redirects to `/nl/coaching` — the locale prefix is preserved
  and the double slash never reaches the downstream stack.

**Default: off.** Leaving it off preserves the historical forgiving behaviour,
so turning it on is an opt-in, backwards-compatible tightening. The trade-off is
one extra redirect round-trip for non-canonical requests (typically only bots
and mistyped links) in exchange for a single canonical URL per route.

Redirect targets are always origin-form — a path beginning with exactly one
`/`. Collapsing every run of slashes to one makes a protocol-relative
`//evil.com` spelling impossible to emit, and
[`SafeRedirect`](../src/Http/SafeRedirect.php) rejects it as a second line of
defence, so a slash variant can never be turned into an open redirect.

## Host-based routing

Routes can be constrained to specific hostnames using the `host` parameter:

```php
$router->add(new Route(
    methods: [Method::GET],
    path: '/dashboard',
    handler: ApiDashboardHandler::class,
    host: 'api.example.com',
));
```

### Host parameters

Host patterns support `{param}` placeholders, extracted as route parameters:

```php
$router->add(new Route(
    methods: [Method::GET],
    path: '/dashboard',
    handler: TenantDashboardHandler::class,
    host: '{tenant}.app.com',
));

// Request to acme.app.com/dashboard:
// $matched->parameter('tenant') === 'acme'
```

### Group-level host

Route groups can set a host for all contained routes:

```php
$apiGroup = new RouteGroup('/api', host: 'api.example.com');
$apiGroup->add(Route::get('/users', ListUsersHandler::class));
$apiGroup->add(Route::get('/posts', ListPostsHandler::class));

$router->addGroup($apiGroup);
// Both routes require Host: api.example.com
```

Individual routes within a group can override the group's host.

### Matching behavior

- Routes without a `host` pattern match any host
- Routes with a `host` pattern only match when the request `Host` header matches
- Host comparison is case-insensitive (RFC 4343)
- When no host is provided to the router, host-constrained routes are skipped

## Method enum

`Pulsar\Http\Method` is a string-backed enum with domain methods:

```php
Method::GET->isSafe();       // true - no side effects
Method::POST->isIdempotent(); // false - not idempotent
Method::PUT->mayHaveBody();   // true - can carry body
Method::fromString('post');   // Case-insensitive creation
```

## ResponseStatus enum

`Pulsar\Http\ResponseStatus` is an int-backed enum covering all standard HTTP status codes:

```php
ResponseStatus::OK->reasonPhrase();        // "OK"
ResponseStatus::NotFound->isClientError(); // true
ResponseStatus::InternalServerError->isServerError(); // true
```
