# Banking Pack Setup Guide

## Overview

The Banking control pack provides a starting point for financial service applications built on Pulsar. It includes entity scaffolds, configuration stubs, and test templates for common banking domain objects.

## Getting Started

### 1. Create a New Project with the Banking Pack

```bash
pulsar new my-banking-app --pack=banking
```

### 2. Review the Generated Structure

```
my-banking-app/
  config/
    encryption.php       # Encryption-at-rest configuration
    audit.php            # Audit trail configuration
    pci-logging.php      # PCI-compliant logging setup
  src/
    Entity/
      Transaction.php    # Financial transaction entity
      Account.php        # Bank account entity
      PaymentIntent.php  # Payment intent entity
      KycProfile.php     # KYC verification profile
  tests/
    Unit/
      Entity/
        TransactionTest.php
        PaymentFlowTest.php
  CONTROLS.md            # Controls coverage report
  NOT-CERTIFIED.md       # Compliance disclaimer
```

### 3. Configure Encryption

Edit `config/encryption.php` to set your encryption provider and key management strategy. Do **not** use the default values in production.

### 4. Set Up Audit Logging

Edit `config/audit.php` to configure your audit trail storage backend and retention policy.

### 5. Implement Domain Logic

The entity stubs provide a starting structure. Implement your business rules, validation logic, and data access layer according to your requirements.

### 6. Run Tests

```bash
composer test
```

## Security Considerations

- Never store encryption keys in source control
- Use a Hardware Security Module (HSM) or certified KMS in production
- Enable TLS for all network communication
- Implement proper access controls at every layer
- Log all access to sensitive data for audit purposes

## Next Steps

See `CONTROLS.md` for a detailed mapping of which regulatory controls this pack supports scaffolding for, and `NOT-CERTIFIED.md` for important disclaimers about what this pack does and does not provide.
