# Pulsar Architecture

This document describes the high-level architecture of the Pulsar Framework.

## Design Principles

### 1. Small, Fast Core

The framework core is intentionally minimal. It provides:
- Kernel lifecycle management
- Service container (PSR-11 compatible)
- HTTP abstraction
- Router
- Basic observability hooks

Everything else is an extension.

### 2. Explicit Over Magic

- No auto-discovery of services unless explicitly configured
- Constructor injection preferred over service locators
- Type-safe configuration
- Predictable boot sequence

### 3. Extension-First

The core and first-party features use the same extension API that third parties use.
There is no "privileged" access for built-in functionality.

### 4. Compile-Time Over Runtime

Where possible, work is shifted from runtime to compile/build time:
- Container bindings can be compiled
- Routes can be cached
- Configuration can be validated at build time

## Core Components

### Kernel (`src/Core/`)

The kernel manages the application lifecycle:

```
Boot → Configure → Handle Request → Send Response → Shutdown
```

Key responsibilities:
- Load configuration
- Initialize service container
- Register extensions
- Delegate request handling

### Container (`src/Container/`)

PSR-11 compatible dependency injection container.

Features:
- Interface-to-implementation binding
- Factory functions
- Singleton and transient lifetimes
- Compiled container support (future)

### HTTP (`src/Http/`)

HTTP abstraction layer.

Components:
- Request representation
- Response representation
- Middleware pipeline

### Router (`src/Routing/`)

URL routing with support for:
- Static routes
- Parameterized routes
- Route groups
- Middleware assignment

### Console (`src/Console/`)

CLI application framework.

Features:
- Command discovery
- Argument parsing
- Output formatting

### Extensibility (`src/Extensibility/`)

Extension system for adding functionality.

Extension lifecycle:
1. Discover (find `pulsar.json` manifests)
2. Validate (check compatibility)
3. Register (bind services, routes, commands)
4. Boot (initialize extension)

### Configuration (`src/Config/`)

Typed configuration system with deterministic load order.

Pipeline:
1. OS environment variables (highest priority)
2. `.env` file (optional, never overrides OS vars)
3. PHP config files (`config/app.php`, `config/observability.php`)
4. Runtime overrides (`ConfigOverrides`, merged via `array_replace_recursive`)
5. Typed DTO construction (`AppConfig::fromArray()`, `ObservabilityConfig::fromArray()`)

Components:
- `Environment` — loads and merges env vars from OS + `.env` file
- `EnvironmentMode` — backed enum (`Local`, `Staging`, `Production`) with debug defaults
- `AppConfig` / `ObservabilityConfig` — readonly DTOs with `fromArray()` factories
- `ConfigRepository` — typed store keyed by class name
- `ConfigManager` — orchestrator that runs the full pipeline
- `ConfigLoaderInterface` — extension point for custom config DTOs (not consumed by core in 0.3.0)

### Error Handling (`src/ErrorHandling/`)

Centralized exception handling with environment-aware rendering.

Flow: Exception → resolve HTTP status → resolve headers → log with context → render response body.

Components:
- `ExceptionHandler` — central handler resolving status, headers, logging, and rendering
- `HttpException` / `HttpExceptionInterface` — exceptions that map to specific HTTP status codes
- `DevelopmentRenderer` — detailed HTML with trace, request details, previous exceptions (debug=true)
- `ProductionRenderer` — safe generic HTML, no sensitive info exposed (debug=false)

Status resolution:
- `HttpExceptionInterface` → its `getStatusCode()`
- `RoutingException` 404 → `NotFound`, 405 → `MethodNotAllowed` with `Allow` header
- Default → `InternalServerError` (500)

### Security (`src/Security/`)

Security primitives:
- Session management
- CSRF protection
- Rate limiting
- Encryption utilities

### Observability (`src/Observability/`)

In-house observability suite:
- Structured logging (PSR-3 compatible)
- Metrics collection
- Distributed tracing
- Audit logging

#### Logging (`src/Observability/Log/`)

PSR-3 compliant structured logging with JSON lines output.

Components:
- `Logger` — implements `Psr\Log\LoggerInterface` with level threshold filtering
- `LogLevel` — backed string enum with numeric severity (0=emergency..7=debug) and `meetsThreshold()`
- `LogEntry` — readonly value object with UTC timestamp, level, message, context, channel
- `LogFormatter` — JSON line formatter with PSR-3 `{placeholder}` interpolation and `Throwable` serialization
- `LogSinkInterface` — output destination contract
- `FileSink` — appends JSON lines to file, creates directories, uses `LOCK_EX`
- `StreamSink` — writes JSON lines to PHP streams (`php://stderr`, etc.)

Design decisions:
- Sink write failures are silently swallowed (logging never crashes a request)
- `Logger::fromConfig()` factory builds a configured logger from `ObservabilityConfig`
- Registered in container as both `Psr\Log\LoggerInterface` and `Pulsar\Observability\Log\Logger`

## Module System (HMVC)

Pulsar uses Hierarchical Model-View-Controller for large applications.

A module is:
- Self-contained (own controllers, views, models)
- Explicitly bounded (declared dependencies)
- Versioned (can evolve independently)

Module structure:
```
modules/<module-name>/
├─ pulsar.json      # Module manifest
├─ src/
│  ├─ Controllers/
│  ├─ Models/
│  └─ Services/
├─ views/
├─ routes/
└─ tests/
```

## Request Lifecycle

1. Web server receives request
2. `public/index.php` creates kernel (optionally with `ConfigManager`)
3. Kernel boots:
   a. If `ConfigManager` provided: load config → register config services → create logger → create exception handler
   b. Register extensions
   c. Boot extensions
4. Request is parsed and wrapped
5. Router matches route
6. Middleware pipeline executes
7. Controller handles request
8. Response is returned through middleware
9. If an exception occurs and `ExceptionHandler` is present: log exception, render error response
10. Response is sent
11. Kernel shuts down

## Directory Layout

See `docs/REPOSITORY_STRUCTURE.md` for the complete directory structure.

## Extension Points

Extensions can hook into:
- Service container (register bindings)
- Router (add routes)
- Console (add commands)
- Middleware (add global middleware)
- Event dispatcher (listen to events)
- Configuration (provide defaults)
