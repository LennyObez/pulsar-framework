# ADR-0003: Non-PSR-7 HTTP Abstractions

## Status

Accepted

## Context

Most PHP frameworks adopt PSR-7 (`psr/http-message`) for HTTP request and response objects, providing interoperability with PSR-15 middleware and third-party HTTP libraries. Pulsar targets PHP 8.5+ exclusively and prioritizes compile-time safety, minimal dependency surface, and ergonomic developer experience for regulated domains.

PSR-7 was designed for PHP 5.x–7.x. Its API carries historical baggage:

- `StreamInterface` for response bodies adds complexity rarely needed in framework internals.
- Immutability is enforced by convention (`with*()` cloning) rather than by the language. PSR-7 objects can be subclassed with mutable state.
- Message factory interfaces (`PSR-17`) add another layer of indirection.
- HTTP methods and status codes are stringly typed - no enum support.

PHP 8.2+ `readonly class` provides compile-time immutability guarantees that PSR-7 cannot offer.

## Decision

Pulsar uses its own HTTP abstractions (`Pulsar\Http\Request`, `Pulsar\Http\Response`) instead of implementing PSR-7.

Key design choices:

- **`readonly class`** - compile-time immutability, no `with*()` cloning ceremony.
- **Backed enums** - `Method` and `ResponseStatus` are string/int-backed enums with domain methods (e.g., `ResponseStatus::isSuccessful()`, `Method::isSafe()`).
- **No StreamInterface** - response bodies are strings. Streaming responses use a dedicated `StreamedResponse` path.
- **Zero external dependencies** - no `psr/http-message`, no `psr/http-factory`, no `psr/http-server-handler`.
- **Unified input** - `Request::all()` merges JSON body, POST, and query with clear precedence rules.

## Consequences

### Positive

- **Compile-time safety.** `readonly` prevents accidental mutation. Enums eliminate invalid method/status values at the type level.
- **Simpler API.** No factory interfaces, no stream wrappers, no `with*()` chains for common operations.
- **Zero dependency surface.** No PSR packages to track, audit, or version-constrain.
- **Better IDE support.** Backed enums provide exhaustive autocompletion for methods and status codes.

### Negative

- **No PSR-15 middleware interop.** Third-party PSR-15 middleware cannot be dropped in without an adapter. Pulsar must provide its own middleware contracts.
- **No PSR-7 library ecosystem.** HTTP client libraries, testing tools, and other PSR-7-based packages require bridge adapters.
- **Learning curve.** Developers familiar with PSR-7 must learn Pulsar's API, even though the concepts are identical.

### Neutral

- **PSR-7 bridge is a first-party extension.** Consistent with ADR-0004 (extension-first architecture), a PSR-7 bridge extension exists at `extensions/psr7-bridge/` providing bidirectional adapters (`Request → ServerRequestInterface`, `ResponseInterface → Response`) and a PSR-15 middleware adapter. This allows integration with PSR-7/PSR-15 ecosystem libraries without coupling the core to PSR interfaces.
- **`Request::all()` precedence is fixed.** Merge order is: JSON body > POST > query. This is documented in `docs/http.md` and enforced by tests. The order is a stable API contract - changing it would be a breaking change.
