# PHP Feature Matrix

This document tracks the intentional usage of PHP 8.0–8.5 language features throughout the Pulsar codebase.

## Purpose

Pulsar targets PHP 8.5+ and deliberately uses modern PHP features where they provide clear benefits:
- Reduced runtime overhead
- Improved type safety
- Better developer experience
- Clearer intent

This matrix maps features to their actual usage in the codebase, ensuring coverage is intentional and beneficial.

## Feature Coverage

### PHP 8.0 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Named arguments | Yes | `src/Http/Request.php`, `src/Http/Response.php`, `src/Config/AppConfig.php`, `src/Observability/Log/LogEntry.php`, `src/ErrorHandling/HttpException.php` | Improved readability for constructor calls with many parameters |
| Attributes | Yes | `tests/Unit/**/*Test.php` | PHPUnit test configuration (#[Test], #[CoversClass]) |
| Constructor property promotion | Yes | `src/Http/Request.php`, `src/Http/Response.php`, `src/Http/HeaderBag.php`, `src/Routing/Route.php`, `src/Routing/MatchedRoute.php`, `src/Http/Middleware/MiddlewarePipeline.php`, `src/Config/AppConfig.php`, `src/Config/LoggingChannelConfig.php`, `src/Observability/Log/LogEntry.php`, `src/ErrorHandling/HttpException.php`, `src/ErrorHandling/ExceptionHandler.php` | Reduced boilerplate for value objects and service classes |
| Union types | Yes | `src/Container/ContainerInterface.php`, `src/Container/Container.php`, `src/Http/Middleware/MiddlewarePipeline.php` | Precise type declarations for flexible APIs |
| Match expression | Yes | `src/Http/Method.php`, `src/Http/ResponseStatus.php`, `src/Config/EnvironmentMode.php`, `src/Config/AppConfig.php`, `src/Observability/Log/LogLevel.php` | Cleaner exhaustive enum matching |
| Nullsafe operator (`?->`) | Planned | - | Null handling |
| `str_contains`, `str_starts_with`, `str_ends_with` | Yes | `src/Http/HeaderBag.php`, `src/Routing/Route.php`, `src/Config/Environment.php` | Native string operations |
| `throw` as expression | Planned | - | Inline error handling |

### PHP 8.1 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Enums | Yes | `src/Container/BindingType.php`, `src/Http/Method.php`, `src/Http/ResponseStatus.php`, `src/Config/EnvironmentMode.php`, `src/Observability/Log/LogLevel.php` | Type-safe constants with methods |
| Fibers | Planned | - | Async operations (scheduler, jobs) |
| Readonly properties | Yes | `src/Http/HeaderBag.php`, `src/Http/Request.php`, `src/Http/Response.php`, `src/Routing/Route.php`, `src/Routing/MatchedRoute.php`, `src/ErrorHandling/HttpException.php`, `src/ErrorHandling/ExceptionHandler.php` | Immutability for value objects and service fields |
| First-class callables | Yes | `src/Core/Kernel.php`, `src/Http/Middleware/MiddlewarePipeline.php` | Clean callback passing with `fn()` syntax |
| Intersection types | Planned | - | Precise typing |
| `never` return type | Planned | - | Exit/throw functions |
| Final class constants | Yes | `src/Core/Version.php` | Version constants |
| `array_is_list` | Planned | - | List validation |

### PHP 8.2 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Readonly classes | Yes | `src/Http/HeaderBag.php`, `src/Http/Request.php`, `src/Http/Response.php`, `src/Routing/Route.php`, `src/Routing/MatchedRoute.php`, `src/Config/AppConfig.php`, `src/Config/ObservabilityConfig.php`, `src/Config/LoggingChannelConfig.php`, `src/Observability/Log/LogEntry.php` | Fully immutable value objects and config DTOs |
| `true`, `false`, `null` as standalone types | Planned | - | Precise return types |
| Disjunctive Normal Form (DNF) types | Planned | - | Complex type constraints |
| Traits with constants | Planned | - | Shared constants |
| `Random\Randomizer` | Planned | - | Secure randomness |

### PHP 8.3 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Typed class constants | Planned | - | Constant type safety |
| `#[Override]` attribute | Planned | - | Method override verification |
| `json_validate` | Planned | - | JSON validation |
| Dynamic class constant fetch | Planned | - | Enum/constant access |

### PHP 8.4 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Property hooks | Planned | - | Custom property behavior |
| Asymmetric visibility | Planned | - | Read-only public properties |
| `new` in initializers | Yes | `src/Http/Response.php` | Default HeaderBag in constructor |
| `#[Deprecated]` attribute | Planned | - | Deprecation warnings |

### PHP 8.5 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Pipe operator (`\|>`) | Planned | - | Functional pipelines |
| Clone with (`clone $obj with {}`) | Planned | - | Immutable modifications |
| `#[NoDiscard]` attribute | Planned | - | Prevent ignored return values |
| Array unpacking in const expressions | Planned | - | Compile-time array building |

## Guidelines

### When to Use a Feature

Use a feature when it:
1. Reduces the chance of bugs
2. Makes the code more readable
3. Improves performance
4. Is idiomatic for the use case

### When NOT to Use a Feature

Avoid features when:
1. The usage would be contrived or forced
2. The benefit is marginal
3. It would confuse developers unfamiliar with PHP 8.x

## Updating This Matrix

When adding new feature usage:
1. Implement the feature naturally in code
2. Update this matrix with the location and rationale
3. Ensure the feature is tested

When a "Planned" feature is implemented:
1. Change status to "Yes"
2. Add location(s)
3. Document the rationale
