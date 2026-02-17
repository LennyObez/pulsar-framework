# API Pack Setup Guide

## Overview

The API scaffolding pack provides a starting point for API-only applications built on Pulsar. It includes middleware scaffolds, configuration stubs, and test templates for common API patterns.

## Getting Started

### 1. Create a New Project with the API Pack

```bash
pulsar new my-api --preset=api --pack=api
```

### 2. Review the Generated Structure

```
my-api/
  config/
    api.php              # API configuration
    rate-limiting.php    # Rate limiting configuration
    auth.php             # Authentication configuration
  src/
    Http/
      OpenApiSpec.php        # OpenAPI spec generator stub
      Middleware/
        AuthMiddleware.php       # Authentication middleware stub
        RateLimitMiddleware.php  # Rate limiting middleware stub
        VersionMiddleware.php    # API versioning middleware stub
  tests/
    Unit/
      Http/
        Middleware/
          AuthMiddlewareTest.php
          RateLimitMiddlewareTest.php
  SCAFFOLDING.md         # Scaffolding coverage report
  NOT-CERTIFIED.md       # Compliance disclaimer
```

### 3. Configure Authentication

Edit `config/auth.php` to set up your authentication strategy (API keys, JWT, OAuth 2.0).

### 4. Configure Rate Limiting

Edit `config/rate-limiting.php` to define rate limits appropriate for your API capacity.

### 5. Run Tests

```bash
composer test
```

## Next Steps

See `SCAFFOLDING.md` for scaffolding coverage details and `NOT-CERTIFIED.md` for important disclaimers.
