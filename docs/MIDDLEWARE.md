# Middleware

Pulsar uses a pipeline-based middleware system. Middleware wraps request handling, allowing cross-cutting concerns like authentication, logging, CORS, and validation.

## Architecture

### Pipeline (FIFO)

Middleware is executed in the order it is added — **first in, first out**. Each middleware receives the request and a `$next` callable. It can:

1. Inspect or modify the request before calling `$next`
2. Call `$next($request)` to pass control to the next middleware (or the handler)
3. Inspect or modify the response after `$next` returns
4. Short-circuit by returning a response without calling `$next`

```
Request → Middleware A → Middleware B → Middleware C → Handler
                                                        ↓
Response ← Middleware A ← Middleware B ← Middleware C ← Response
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

### Short-Circuit

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

## Global vs Route Middleware

### Global Middleware

Applied to every request via the kernel:

```php
$kernel->addMiddleware(CorsMiddleware::class);
$kernel->addMiddleware(new LoggingMiddleware($logger));
```

### Route Middleware

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

## Named Groups and Aliases

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

1. **Alias** — returns the single mapped middleware
2. **Group** — returns the list of middleware in the group
3. **Class-string** — returned as-is for direct resolution

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

### Behavior

- Requests within the limit pass through with rate-limit headers added to the response
- Requests exceeding the limit receive a **429 Too Many Requests** JSON response with a `Retry-After` header

### Response Headers

Every response includes:

| Header                  | Description                              |
| ----------------------- | ---------------------------------------- |
| `X-RateLimit-Limit`     | Maximum requests allowed in the window   |
| `X-RateLimit-Remaining` | Requests remaining in the current window |

When rate-limited, the response also includes:

| Header        | Description                             |
| ------------- | --------------------------------------- |
| `Retry-After` | Seconds until the current window resets |

### Rate-Limited Response

```json
{
  "error": "Too Many Requests",
  "retry_after": 42
}
```

HTTP status is always **429 Too Many Requests**.

### Key Resolution

By default, the rate limiter keys requests by client IP address (`REMOTE_ADDR` server variable). The in-memory limiter is suitable for single-process deployments. For distributed deployments, replace with a cache-backed implementation.
