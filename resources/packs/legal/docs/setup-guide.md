# Legal Pack Setup Guide

## Overview

The Legal scaffolding pack provides a starting point for legal practice management applications built on Pulsar. It includes entity scaffolds, configuration stubs, and test templates for common legal domain objects.

## Getting Started

### 1. Create a New Project with the Legal Pack

```bash
pulsar new my-legal-app --pack=legal
```

### 2. Review the Generated Structure

```
my-legal-app/
  config/
    retention.php   # Document retention policy configuration
    audit.php       # Audit trail configuration
  src/
    Entity/
      LegalCase.php # Case entity
      Document.php  # Document entity with privilege flag
      Client.php    # Client entity
      Deadline.php  # Deadline/due date entity
  tests/
    Unit/
      Entity/
        LegalCaseTest.php
        DocumentTest.php
  SCAFFOLDING.md    # Scaffolding coverage report
  NOT-CERTIFIED.md  # Compliance disclaimer
```

### 3. Configure Document Retention

Edit `config/retention.php` to define retention periods and destruction rules for different document categories.

### 4. Set Up Audit Logging

Edit `config/audit.php` to configure audit trail storage and the events to track.

### 5. Run Tests

```bash
composer test
```

## Next Steps

See `SCAFFOLDING.md` for scaffolding coverage details and `NOT-CERTIFIED.md` for important disclaimers.
