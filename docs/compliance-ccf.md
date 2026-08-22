# Common Control Framework (CCF)

Pulsar provides a programmatic Common Control Framework that maps framework features to regulatory controls across multiple compliance frameworks.

> **Disclaimer**: This system documents framework control coverage. It does not constitute a compliance certification. Compliance is an organizational responsibility that extends beyond technical controls.

## Architecture

### Control Catalog

The `ControlCatalog` is a registry of regulatory controls with unique IDs, descriptions, and implementation status:

```php
$catalog = new ControlCatalog();
$catalog->register(new Control(
    id: 'SOC2-CC6.1',
    framework: 'soc2',
    title: 'Logical and Physical Access Controls',
    description: 'The entity implements logical access security software...',
    status: ControlStatus::Implemented,
    frameworkFeatures: ['authentication', 'authorization', 'rbac'],
));
```

### Control Mapping

The `ControlMapping` creates bidirectional relationships between framework features and controls:

```php
$mapping = new ControlMapping($catalog);
$mapping->map('audit_logging', 'SOC2-CC7.2');
$mapping->map('audit_logging', 'HIPAA-164.312(b)');

// Which controls does audit_logging cover?
$controls = $mapping->controlsForFeature('audit_logging');

// Which features cover SOC2-CC7.2?
$features = $mapping->featuresForControl('SOC2-CC7.2');
```

### Control Verifier

The `ControlVerifier` runs automated checks to determine whether controls are active:

```php
$verifier = new ControlVerifier($catalog);
$verifier->registerVerifier('SOC2-CC7.2', function (): VerificationResult {
    // Check that audit logging is configured and operational
    return VerificationResult::pass('SOC2-CC7.2', 'Audit logging active');
});

$results = $verifier->verifyFramework('soc2');
$summary = ControlVerifier::summarize($results);
```

### Compliance Report

The `ComplianceReport` generates comprehensive coverage reports:

```php
$report = new ComplianceReport($catalog, $mapping, $verifier);
$result = $report->generate('soc2');
// Returns: framework, summary, verification results, per-control details
```

## Supported Frameworks

| Framework | Module          | Controls                             |
| --------- | --------------- | ------------------------------------ |
| SOC 2     | `Soc2Mapping`   | Trust Service Criteria (CC series)   |
| HIPAA     | `HipaaMapping`  | Security Rule (Technical Safeguards) |
| GDPR      | `GdprMapping`   | Articles 5, 25, 30, 32, 33, 35       |
| PCI DSS   | `PciDssMapping` | PCI DSS v4.0 Requirements            |

### Registering Framework Mappings

```php
$catalog = new ControlCatalog();
Soc2Mapping::register($catalog);
HipaaMapping::register($catalog);
GdprMapping::register($catalog);
PciDssMapping::register($catalog);
```

## Evidence Collection

Evidence proves that controls are implemented and operational:

```php
$store = new InMemoryEvidenceStore();
$collector = new EvidenceCollector($store);

// Manual evidence collection
$collector->collect(
    controlId: 'SOC2-CC6.1',
    type: EvidenceType::AccessControl->value,
    description: 'RBAC policy configured with 5 roles',
    data: ['roles' => ['admin', 'manager', 'user', 'auditor', 'readonly']],
);

// Automated collection via registered collectors
$collector->registerCollector(EvidenceType::Configuration->value, function (string $controlId): array {
    // Automatically collect configuration evidence
    return [...];
});
```

## SBOM Generation

Generate a CycloneDX Software Bill of Materials:

```bash
composer sbom
# or
php scripts/generate_sbom.php sbom.json
```

The SBOM includes all dependencies, versions, licenses, and package URLs in CycloneDX 1.5 format.

## Dashboard Integration

The `ComplianceStatusProvider` exposes compliance data for admin dashboards:

```php
$provider = new ComplianceStatusProvider($catalog, $mapping, $verifier, $report);

// High-level overview
$overview = $provider->overview();

// Detailed framework status
$detail = $provider->frameworkDetail('hipaa');

// Feature-to-control coverage map
$coverageMap = $provider->featureCoverageMap();
```

## Control Status Lifecycle

| Status           | Meaning                                                              |
| ---------------- | -------------------------------------------------------------------- |
| `implemented`    | Control fully covered by framework features                          |
| `partial`        | Control partially covered; additional organizational controls needed |
| `planned`        | Control coverage planned for a future release                        |
| `not_applicable` | Control not relevant to the framework layer                          |
