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
