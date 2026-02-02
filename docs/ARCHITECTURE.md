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
2. `public/index.php` creates kernel
3. Kernel boots (loads config, container, extensions)
4. Request is parsed and wrapped
5. Router matches route
6. Middleware pipeline executes
7. Controller handles request
8. Response is returned through middleware
9. Response is sent
10. Kernel shuts down

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
