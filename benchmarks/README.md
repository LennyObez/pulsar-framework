# Pulsar Benchmarks

This directory contains performance benchmarks for Pulsar framework components.

## Strategy

Benchmarks measure critical hot paths to ensure performance leadership:

1. **Container Resolution** - DI container lookup and autowiring performance
2. **Router Matching** - Route matching with varying route counts and parameter complexity
3. **Middleware Pipeline** - Overhead of middleware stack execution
4. **Request/Response** - HTTP object creation and manipulation

## Running Benchmarks

Benchmarks use [PHPBench](https://phpbench.readthedocs.io/) for accurate measurement.

```bash
# Install PHPBench (dev dependency)
composer require --dev phpbench/phpbench

# Run all benchmarks
vendor/bin/phpbench run benchmarks --report=default

# Run specific benchmark
vendor/bin/phpbench run benchmarks/ContainerBench.php --report=default

# Run with iterations for accuracy
vendor/bin/phpbench run benchmarks --iterations=10 --revs=1000 --report=default
```

## Benchmark Guidelines

1. **Isolation** - Each benchmark should measure one specific operation
2. **Realistic Data** - Use production-representative data sizes
3. **Multiple Scenarios** - Test best case, average case, and worst case
4. **Comparison** - Where applicable, compare against baseline implementations

## Current Benchmarks

| Benchmark | Description | Status |
|-----------|-------------|--------|
| ContainerBench | DI container resolution performance | Placeholder |
| RouterBench | Route matching performance | Placeholder |

## Performance Targets

These are aspirational targets for core operations:

| Operation | Target | Notes |
|-----------|--------|-------|
| Container::get (cached) | < 100ns | Singleton lookup |
| Container::get (factory) | < 1μs | With autowiring |
| Router::match (10 routes) | < 5μs | Static routes |
| Router::match (100 routes) | < 50μs | With parameters |
| Middleware pipeline (5 deep) | < 10μs | Empty middleware |

## Adding New Benchmarks

1. Create a new `*Bench.php` file in this directory
2. Use PHPBench annotations for configuration
3. Document what is being measured and why
4. Include realistic data providers
5. Update this README with the new benchmark
