# SaaS Pack Setup Guide

## Overview

The SaaS scaffolding pack provides a starting point for multi-tenant SaaS applications built on Pulsar. It includes entity scaffolds, configuration stubs, and test templates for common SaaS domain objects.

## Getting Started

### 1. Create a New Project with the SaaS Pack

```bash
pulsar new my-saas-app --pack=saas
```

### 2. Review the Generated Structure

```
my-saas-app/
  config/
    tenancy.php        # Multi-tenant configuration
    billing.php        # Billing integration configuration
    feature-flags.php  # Feature flag configuration
  src/
    Entity/
      Tenant.php       # Tenant entity
      Subscription.php # Subscription entity
      Plan.php         # Billing plan entity
      Feature.php      # Feature flag entity
  tests/
    Unit/
      Entity/
        TenantTest.php
        SubscriptionTest.php
  SCAFFOLDING.md       # Scaffolding coverage report
  NOT-CERTIFIED.md     # Compliance disclaimer
```

### 3. Configure Multi-Tenancy

Edit `config/tenancy.php` to define your tenant isolation strategy and tenant resolution approach.

### 4. Set Up Billing

Edit `config/billing.php` to configure your billing provider integration.

### 5. Run Tests

```bash
composer test
```

## Next Steps

See `SCAFFOLDING.md` for scaffolding coverage details and `NOT-CERTIFIED.md` for important disclaimers.
