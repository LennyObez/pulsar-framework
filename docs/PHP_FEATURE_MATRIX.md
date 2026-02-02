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
| Named arguments | Planned | - | Improved readability for optional parameters |
| Attributes | Yes | `tests/Unit/Core/KernelTest.php` | PHPUnit test configuration |
| Constructor property promotion | Planned | - | Reduced boilerplate |
| Union types | Planned | - | Precise type declarations |
| Match expression | Planned | - | Cleaner switch alternatives |
| Nullsafe operator (`?->`) | Planned | - | Null handling |
| `str_contains`, `str_starts_with`, `str_ends_with` | Planned | - | String operations |
| `throw` as expression | Planned | - | Inline error handling |

### PHP 8.1 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Enums | Planned | - | Type-safe constants |
| Fibers | Planned | - | Async operations (scheduler, jobs) |
| Readonly properties | Planned | - | Immutability |
| First-class callables | Planned | - | Callback clarity |
| Intersection types | Planned | - | Precise typing |
| `never` return type | Planned | - | Exit/throw functions |
| Final class constants | Yes | `src/Core/Version.php` | Version constants |
| `array_is_list` | Planned | - | List validation |

### PHP 8.2 Features

| Feature | Used | Location(s) | Rationale |
|---------|------|-------------|-----------|
| Readonly classes | Planned | - | Immutable DTOs |
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
| `new` in initializers | Planned | - | Default values |
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
