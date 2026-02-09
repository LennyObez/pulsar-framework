# Error Handling

Pulsar provides centralized exception handling with separate development and production renderers. All exceptions flow through a single `ExceptionHandler` that resolves HTTP status codes, logs context, and renders appropriate responses.

## ExceptionHandler Flow

```
Exception thrown
    │
    ├── Resolve HTTP status from exception type
    │     ├── HttpExceptionInterface → its getStatusCode()
    │     ├── RoutingException (404) → NotFound
    │     ├── RoutingException (405) → MethodNotAllowed
    │     └── Default → InternalServerError (500)
    │
    ├── Resolve response headers
    │     ├── HttpExceptionInterface → its getHeaders()
    │     ├── RoutingException (405) → Allow header
    │     └── Default → none
    │
    ├── Log exception with structured context
    │     ├── 5xx → logger->error()
    │     └── 4xx → logger->warning()
    │
    └── Render response body via ExceptionRendererInterface
          ├── DevelopmentRenderer (debug=true)
          └── ProductionRenderer (debug=false)
```

## Dev vs Prod Renderers

The renderer choice is made once at kernel boot based on `AppConfig::$debug`:

- **DevelopmentRenderer**: Detailed HTML page with exception class/message, full stack trace, request details (method, URI, headers, query), and chained previous exceptions. All values HTML-escaped. Inline CSS.
- **ProductionRenderer**: Safe generic HTML. No class names, stack traces, or file paths exposed. Generic messages per status category.

### Production Messages

| Status | Message                                                  |
| ------ | -------------------------------------------------------- |
| 404    | "The page you are looking for could not be found."       |
| 403    | "You do not have permission to access this resource."    |
| 405    | "The request method is not supported for this resource." |
| 4xx    | "The request could not be processed."                    |
| 5xx    | "An internal error occurred. Please try again later."    |

## HttpException

Use `HttpException` to throw exceptions that map to specific HTTP status codes:

```php
throw HttpException::notFound('User not found');
throw HttpException::forbidden('Insufficient permissions');
throw HttpException::badRequest('Invalid email format');
throw HttpException::serviceUnavailable('Database maintenance');

// Custom status with headers
throw new HttpException(
    ResponseStatus::TooManyRequests,
    'Rate limit exceeded',
    ['Retry-After' => '60'],
);
```

## HttpExceptionInterface

Implement this interface for custom exception types that should map to specific HTTP responses:

```php
class PaymentRequiredException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): ResponseStatus
    {
        return ResponseStatus::PaymentRequired;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
```

## RoutingException Handling

`RoutingException` thrown by the router is automatically handled:

- **404 (Not Found)**: Status `NotFound`, no extra headers
- **405 (Method Not Allowed)**: Status `MethodNotAllowed`, `Allow` header with permitted methods

## Logger Integration

Every exception is logged with structured context:

```json
{
  "exception": "<Throwable instance>",
  "status": 500,
  "method": "GET",
  "uri": "/api/data"
}
```

5xx exceptions log at `error` level. 4xx exceptions log at `warning` level.

## Kernel Integration

When a `ConfigManager` is provided to the Kernel, the exception handler is created during boot and catches all exceptions from the middleware pipeline and route dispatch:

```php
$kernel = new Kernel(configManager: new ConfigManager(
    configPath: __DIR__ . '/config',
));
```

When no `ConfigManager` is provided (e.g. in tests), exceptions propagate as before — full backward compatibility.

## Custom Renderers

Implement `ExceptionRendererInterface` to create custom renderers:

```php
interface ExceptionRendererInterface
{
    public function render(Throwable $exception, Request $request, ResponseStatus $status): string;
}
```

Custom renderers can be injected into `ExceptionHandler` directly for specialized use cases (JSON API errors, CLI rendering, etc.).
