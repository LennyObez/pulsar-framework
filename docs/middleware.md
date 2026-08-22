# Middleware

Pulsar uses a pipeline-based middleware system. Middleware wraps request handling, allowing cross-cutting concerns like authentication, logging, CORS, and validation.

## Architecture

### Pipeline (FIFO)

Middleware is executed in the order it is added - **first in, first out**. Each middleware receives the request and a `$next` callable. It can:

1. Inspect or modify the request before calling `$next`
2. Call `$next($request)` to pass control to the next middleware (or the handler)
3. Inspect or modify the response after `$next` returns
4. Short-circuit by returning a response without calling `$next`

```
Request → Middleware A → Middleware B → Middleware C → Handler
                                                        ↓
Response ← Middleware A ← Middleware B ← Middleware C ← Response
```

### Ordering: `pipe()` vs `prepend()`

`pipe()` appends to the back of the stack, so a middleware you pipe runs
_inside_ everything piped before it. Because the framework's own middleware
(locale prefix, slug rewriting, security headers) is piped during boot, a
project or extension that only ever calls `pipe()` can never place a middleware
_ahead_ of them.

`prepend()` adds to the front, so the middleware runs **outermost** — before
every middleware added so far:

```php
$pipeline->prepend($myMiddleware); // runs before the framework's middleware
```

This matters when a middleware must observe the request before a rewriting
middleware mutates it. `LocalePrefixMiddleware` strips the `/nl` locale prefix
and `LocalizedSlugMiddleware` rewrites localized slugs to canonical keys, so a
middleware piped after them sees the already-rewritten URI. Prepend to see the
URL the visitor actually requested.

### The original request URI

When you need the pre-rewrite URL from _downstream_ of the locale middleware
(canonical/hreflang links, analytics, audit) rather than from an outer
middleware, read the `_original_uri` request attribute. Whichever locale
rewriter runs first records the incoming `UriInterface` there, set-once, before
mutating the URI:

```php
$original = $request->getAttribute(\Pulsar\I18n\Locale\OriginalUriStash::ATTRIBUTE);
// e.g. "/nl/coaching" even though the live URI is now "/coaching"
```

### Contract

```php
interface MiddlewareInterface
{
    public function process(Request $request, callable $next): Response;
}
```

### Example

```php
class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE');
    }
}
```

### Short-circuit

A middleware can return a response directly without calling `$next`, preventing downstream middleware and the handler from executing:

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if (!$this->isAuthenticated($request)) {
            return Response::json(['error' => 'Unauthorized'], ResponseStatus::Unauthorized);
        }
        return $next($request);
    }
}
```

## Global vs route middleware

### Global middleware

Applied to every request via the kernel:

```php
$kernel->addMiddleware(CorsMiddleware::class);
$kernel->addMiddleware(new LoggingMiddleware($logger));
```

### Route middleware

Applied to specific routes via the `Route` constructor:

```php
$router->add(new Route(
    methods: [Method::POST],
    path: '/api/users',
    handler: CreateUserHandler::class,
    middleware: ['auth', CreateUserValidation::class],
));
```

Route middleware strings are resolved through the `MiddlewareRegistry`.

## Named groups and aliases

The `MiddlewareRegistry` provides named groups and aliases for cleaner route definitions.

### Aliases

Map a short name to a single middleware:

```php
$kernel->middlewareRegistry()
    ->alias('auth', AuthMiddleware::class)
    ->alias('cors', CorsMiddleware::class);
```

### Groups

Map a name to an ordered list of middleware:

```php
$kernel->middlewareRegistry()->group('api', [
    CorsMiddleware::class,
    AuthMiddleware::class,
    RateLimitMiddleware::class,
]);
```

### Resolution

When route middleware strings are processed, the registry resolves them in order:

1. **Alias** - returns the single mapped middleware
2. **Group** - returns the list of middleware in the group
3. **Class-string** - returned as-is for direct resolution

```php
// These are equivalent after registry setup:
$registry->resolve('auth');  // [AuthMiddleware::class]
$registry->resolve('api');   // [CorsMiddleware::class, AuthMiddleware::class, ...]
$registry->resolve(SomeMiddleware::class);  // [SomeMiddleware::class]
```

## ValidationMiddleware

`Pulsar\Http\Middleware\ValidationMiddleware` is an abstract middleware for request validation. Subclass it and define validation rules:

```php
class CreateUserValidation extends ValidationMiddleware
{
    protected function rules(Request $request): array
    {
        return [
            'name'  => [new Required(), new StringType(), new MinLength(1)],
            'email' => [new Required(), new Email()],
        ];
    }
}
```

When validation fails, a `ValidationException` is thrown. The `ExceptionHandler` catches it and returns a 422 JSON response automatically.

Usage on a route:

```php
$router->add(new Route(
    methods: [Method::POST],
    path: '/api/users',
    handler: CreateUserHandler::class,
    middleware: [CreateUserValidation::class],
));
```

## RateLimitMiddleware

`Pulsar\Http\Middleware\RateLimitMiddleware` enforces request rate limiting using a fixed-window `RateLimiter`.

### Setup

```php
use Pulsar\Http\RateLimit\RateLimiter;
use Pulsar\Http\Middleware\RateLimitMiddleware;

// Allow 60 requests per 60-second window
$limiter = new RateLimiter(maxAttempts: 60, windowSeconds: 60);
$middleware = new RateLimitMiddleware($limiter);

// Apply globally
$kernel->addMiddleware($middleware);

// Or register as an alias for route-level use
$kernel->middlewareRegistry()->alias('throttle', RateLimitMiddleware::class);
```

### Config-driven wiring (recommended)

The framework wires this for you from `config/security.php` under `rate_limiting`.
When enabled it binds a shared-store `RateLimiterInterface`, builds the
middleware, and registers the **`throttle`** alias — apply it per route or group
(it is deliberately not piped globally, so throttling stays opt-in):

```php
// config/security.php
'rate_limiting' => [
    'enabled' => true,
    'default_limit' => 60,      // requests per window
    'default_window' => 60,     // window length in seconds
    'key_strategy' => 'ip',     // 'ip' | 'route' | 'ip_route'
],
```

```php
// route definition
$router->post('/contact', ContactController::class)->middleware('throttle');
```

The bound limiter is **cache-backed** (`CacheRateLimiter`, PSR-16) whenever a
cache is configured, so counts persist across PHP-FPM workers; it falls back to
the in-memory `RateLimiter` only when no cache is bound.

### Behavior

- Requests within the limit pass through with rate-limit headers added to the response
- Requests exceeding the limit receive a **429 Too Many Requests** JSON response with a `Retry-After` header

### Response headers

Every response includes:

| Header                  | Description                              |
| ----------------------- | ---------------------------------------- |
| `X-RateLimit-Limit`     | Maximum requests allowed in the window   |
| `X-RateLimit-Remaining` | Requests remaining in the current window |

When rate-limited, the response also includes:

| Header        | Description                             |
| ------------- | --------------------------------------- |
| `Retry-After` | Seconds until the current window resets |

### Rate-limited response

```json
{
  "error": "Too Many Requests",
  "retry_after": 42
}
```

HTTP status is always **429 Too Many Requests**.

### Key resolution

The bucket key is chosen by `key_strategy`:

| Strategy   | Bucket                                                       | Use for                                                                |
| ---------- | ------------------------------------------------------------ | ---------------------------------------------------------------------- |
| `ip`       | Per client (IP, plus the authenticated user id when present) | Throttling each caller independently (default)                         |
| `route`    | Per matched route, shared by all clients                     | Capping total load on one expensive endpoint                           |
| `ip_route` | Per client per route                                         | A caller's budget on one endpoint not spending their budget on another |

Client IP is resolved through the configured `TrustedProxy` (honoring
`X-Forwarded-For` behind a trusted proxy); when no IP is available the limiter
falls back to a User-Agent hash, then a method+URI hash, so it never silently
degrades to a single shared bucket.

For distributed deployments the cache-backed `CacheRateLimiter` shares counts
across processes via the PSR-16 cache; it fails **open** (allows the request) if
the cache backend is unavailable, so a store outage never takes the site down.
