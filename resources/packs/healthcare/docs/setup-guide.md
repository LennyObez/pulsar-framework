# Healthcare Pack Setup Guide

## Overview

The Healthcare scaffolding pack provides a starting point for healthcare applications built on Pulsar. It includes entity scaffolds, configuration stubs, and test templates for common healthcare domain objects.

## Getting Started

### 1. Create a New Project with the Healthcare Pack

```bash
pulsar new my-health-app --pack=healthcare
```

### 2. Review the Generated Structure

```
my-health-app/
  config/
    phi-access.php         # PHI access logging configuration
    data-classification.php # Data sensitivity classification
    encryption.php         # Encryption-at-rest configuration
  src/
    Entity/
      Patient.php          # Patient entity
      MedicalRecord.php    # Medical record entity
      Appointment.php      # Appointment entity
      Prescription.php     # Prescription entity
  tests/
    Unit/
      Entity/
        PatientTest.php
        MedicalRecordTest.php
  SCAFFOLDING.md           # Scaffolding coverage report
  NOT-CERTIFIED.md         # Compliance disclaimer
```

### 3. Configure PHI Access Logging

Edit `config/phi-access.php` to configure how access to Protected Health Information is logged and monitored.

### 4. Set Up Data Classification

Edit `config/data-classification.php` to define sensitivity levels for your data fields. This supports the HIPAA minimum necessary standard.

### 5. Implement Domain Logic

The entity stubs provide a starting structure. Implement your business rules, FHIR resource mappings, and data access layer according to your requirements.

### 6. Run Tests

```bash
composer test
```

## Security Considerations

- All PHI must be encrypted at rest and in transit
- Implement audit logging for every PHI access event
- Apply the minimum necessary standard to all data access
- Implement automatic session timeout for clinical workstations
- Maintain access control lists per the principle of least privilege

## Next Steps

See `SCAFFOLDING.md` for a detailed mapping of which regulatory requirements this pack provides scaffolding for, and `NOT-CERTIFIED.md` for important disclaimers about what this pack does and does not provide.
