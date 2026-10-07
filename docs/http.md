# HTTP layer

Pulsar's HTTP core **is** a PSR-7 implementation. `Pulsar\Http\Message\ServerRequest`,
`Response`, `Uri`, `Stream` and `UploadedFile` implement the `Psr\Http\Message`
interfaces; all six PSR-17 factories live in `src/Http/Factory/`; the middleware pipeline
is PSR-15, and `Kernel::handle()` takes a `ServerRequestInterface` and returns a
`ResponseInterface`.

Alongside them sits a second, narrower pair — `Pulsar\Http\Request` and
`Pulsar\Http\Response`, `readonly` value objects reached through a bridge. Both are
supported; this page says which to reach for.

## Two request types, and which to use

|                                                                     | `Pulsar\Http\Message\ServerRequest` | `Pulsar\Http\Request`                       |
| ------------------------------------------------------------------- | ----------------------------------- | ------------------------------------------- |
| Is a PSR-7 `ServerRequestInterface`                                 | yes                                 | no                                          |
| What the kernel hands a controller                                  | yes                                 | via `PsrBridge` / `extensions/psr7-bridge/` |
| Immutability                                                        | PSR-7 `with*()` convention          | `readonly`, enforced by the language        |
| Convenience helpers (`json()`, `query()`, `input()`, `wantsJson()`) | yes                                 | yes                                         |
| Backed-enum `Method` / `ResponseStatus`                             | yes                                 | yes                                         |

**Use `Pulsar\Http\Message\ServerRequest` in controllers.** It is what the pipeline
carries, so nothing converts. Reach for the `readonly` pair when you want the language to
enforce immutability at an application boundary, and cross with `PsrBridge`.

### What the value objects are for

1. **`readonly` by design** - `Pulsar\Http\Request` cannot be mutated at all, where a PSR-7
   message relies on `with*()` discipline. That is a real guarantee and the reason the pair
   still exists.
2. **No `StreamInterface` ceremony** - bodies are strings; streaming has a dedicated
   `StreamedResponse` path.
3. **Enum-backed types** - `Method` and `ResponseStatus` are backed enums with domain
   methods rather than string/int constants. These are used on both paths.

### Interoperability

Third-party PSR-7, PSR-15 and PSR-17 code needs no adapter — the core implements all
three. `extensions/psr7-bridge/` (`pulsar/psr7-bridge`) goes the _other_ way, converting
PSR-7 messages to and from the `readonly` value objects for code that wants that pair at a
boundary.

This section used to be headed "PSR-7 non-adoption rationale" and gave "reduced dependency
surface - no external packages for HTTP message handling" as one of its reasons.
`composer.json` requires eleven PSR packages, four of them HTTP, and the core implements
their interfaces. See
[ADR-0069](adr/0069-a-dependency-you-require-is-not-a-dependency-you-avoided.md) for what is
in force, and [ADR-0003](adr/0003-non-psr7-http-abstractions.md), which it supersedes, for
why the non-PSR-7 position looked right at the time.

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

### `#[NoCacheResponse]` and `NoCacheMiddleware`

`Pulsar\Http\Attribute\NoCacheResponse` marks a controller method — or a whole controller class — as serving data that must not be cached by browsers or intermediate proxies. Where it is enforced, the response answers with:

```http
Cache-Control: no-store, no-cache, must-revalidate
Pragma: no-cache
Expires: 0
```

The attribute is inert on its own. `Pulsar\Http\Middleware\NoCacheMiddleware` (alias `no-cache`) is what enforces it, and it enforces it only on the routes it runs for. Attaching the middleware is not itself a declaration that a route is sensitive: a route whose handler carries no `#[NoCacheResponse]` passes through untouched, which is what lets one application-wide registration coexist with a handful of marked handlers.

**The middleware must be wired where the kernel hands the pipeline the matched route.** It reads the declaration off the dispatched route's handler, which it is given by the kernel through `DispatchedRouteAwareInterface` — not off a request attribute, which every frame between routing and the handler can rewrite. Two positions supply it, and only those two:

```php
// 1. On the route. Scopes the reflection to the sensitive routes.
//    Route middleware is attached through the constructor's `middleware:`
//    argument; the router has no fluent middleware() setter.
$router->add(new Route(
    methods: [Method::GET],
    path: '/accounts/{id}/statement',
    handler: [StatementController::class, 'show'],
    middleware: ['no-cache'],
));
```

```php
// 2. In the PostRoutingPipeline. One registration; every #[NoCacheResponse]
//    in the application is then enforced. Typically from a service provider.
use Pulsar\Http\Middleware\NoCacheMiddleware;
use Pulsar\Http\Middleware\PostRoutingPipeline;

$container->get(PostRoutingPipeline::class)->pipe(NoCacheMiddleware::class);
```

`PostRoutingPipeline` is kernel-owned and marked `#[Internal]`: it is the only registration point that sees the matched route for every route in the application, but it is not covered by the semver guarantees on `#[Api]` types.

Piped into the **global** pipeline it runs before routing, can never learn which handler serves the request, and now throws a `LogicException` naming both supported wirings instead of returning the response unprotected. That is deliberate and is a behaviour change: the alternative — handing back an uncached-but-unmarked response — is the defect this middleware was found in, an operator believing statements of account carried `no-store` while every one of them was cacheable. A `LogicException` on the first request after a deploy is a wiring error you can see; a missing header is not.

This governs what **browsers and proxies** may keep. Pulsar's own shared response cache is a separate, opt-in mechanism with its own refusal rules — see [HTTP response caching](caching.md#http-response-caching-httpcachemiddleware).

### Streaming responses

`Pulsar\Http\Response\StreamedResponse` streams from a generator or iterator with `Transfer-Encoding: chunked`, without buffering the payload.

```php
return StreamedResponse::fromGenerator(static function (): Generator {
    foreach ($rows as $row) {
        yield formatCsvRow($row);
    }
}, 200, ['Content-Type' => 'text/csv']);
```

Two methods reach the payload, and they no longer fight over it:

- `getSource()` returns the iterator to stream. This is what the emitter uses, and what a caller that wants the response to stay streamed should use.
- `getBody()` returns the whole body as a PSR-7 stream. It **materializes** the source — buffering the payload this class exists to avoid buffering — but it is **not destructive**. The source is read once, kept, and answered from the buffer on every later call, `getSource()`'s included.

That matters to middleware authors. Any frame that inspects `getBody()` — a compressor, a hasher, a response cache, a logger — used to consume the single-pass source and keep nothing, leaving the emitter behind it with headers already sent and an empty body, or a `getSource()` that threw. Reading the body now costs memory and costs the streaming property, and costs nothing else. `withBody()` is still unsupported and throws a `RuntimeException`; use a regular `Response` for a non-streamed body.

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

### Match precedence

Registration order decides who owns a key. When two _different_ keys can answer
the same request — a literal path and a placeholder pattern, or a host-constrained
route and a host-less one — the router resolves them in this order:

1. a route constrained to a host that matches the request `Host` header;
2. the host-less static (literal-path) route;
3. host-less dynamic routes, first-registered-wins.

This ordering does not depend on whether the request carries a `Host` header, nor
on whether some unrelated route elsewhere in the table is host-constrained. Both
matchers apply it: the live `Router` and the build-time `CompiledRouteTree`.

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

## Error responses carry security headers

An error produced while dispatching a route is converted to a response at the innermost pipeline handler, so it travels back out through every global middleware — `SecurityHeadersMiddleware` included — and receives the same header set as a `200`. Nothing to configure there.

Two failures cannot do that, because there is no pipeline left to travel back through:

- **boot failed** — a poisoned config, a failing wiring, an extension that threw during registration. `SecurityHeadersMiddleware` is not wired yet.
- **a global middleware threw** — the throw unwinds every frame outside it, `SecurityHeadersMiddleware`'s included.

Both used to leave the kernel with whatever the exception handler put together, which for a typical application handler is no protective header at all. That made the response most likely to be probed the one response in the framework with no CSP, no framing policy and no referrer policy.

The kernel now fills the gaps on those two paths from `ProductionRenderer::LAST_RESORT_HEADERS`:

| Header                    | Value                                                                                                        |
| ------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `Cache-Control`           | `no-store`                                                                                                   |
| `X-Content-Type-Options`  | `nosniff`                                                                                                    |
| `X-Frame-Options`         | `DENY`                                                                                                       |
| `Referrer-Policy`         | `no-referrer`                                                                                                |
| `Content-Security-Policy` | `default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'` |

Two rules govern it, and both matter if you render your own error pages:

- **Only missing headers are added.** A value your exception handler already set is left alone — an `X-Frame-Options: SAMEORIGIN` it chose is not replaced with `DENY`. This static list is not your configured header policy, and the kernel does not overrule a stated value on a path you cannot see.
- **`Content-Type` is never touched.** It describes the payload whoever built the response produced, and the last-resort page's `text/html` would mislabel a JSON error body.

This is additive on the two out-of-pipeline paths only; a 404, 405 or 500 raised during dispatch is unaffected, because it was already picking up the application's configured headers.
