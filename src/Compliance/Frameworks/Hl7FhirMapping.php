<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers HL7 FHIR capability controls into the catalog.
 *
 * Maps Pulsar framework features to HL7 FHIR interoperability requirements
 * they provide coverage for. FHIR controls are functional capabilities
 * rather than regulatory mandates.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Hl7FhirMapping
{
    /**
     * Register HL7 FHIR controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'FHIR-RES-001',
            framework: 'hl7_fhir',
            title: 'FHIR Resource Types',
            description: 'Support core FHIR R4/R5 resource types: Patient, Observation, Encounter, '
                . 'Condition, MedicationRequest, AllergyIntolerance, Procedure, DiagnosticReport. '
                . 'Immutable DTOs with full JSON serialization/deserialization.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_resources', 'fhir_serialization'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-REST-001',
            framework: 'hl7_fhir',
            title: 'FHIR RESTful API',
            description: 'FHIR-conformant REST endpoints supporting read, search, create, update, '
                . 'delete interactions with proper Content-Type headers (application/fhir+json). '
                . 'Includes CapabilityStatement at /metadata.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_rest_api', 'fhir_capability_statement'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-BUNDLE-001',
            framework: 'hl7_fhir',
            title: 'Bundle Support',
            description: 'Bundle resource support for batch and transaction operations. '
                . 'Supports searchset, collection, batch-response, and transaction-response types.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_bundle', 'fhir_batch_operations'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-SEARCH-001',
            framework: 'hl7_fhir',
            title: 'FHIR Search Parameters',
            description: 'Support for FHIR search parameter handling including _id, _lastUpdated, '
                . 'subject, and custom search parameters per resource type.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_search', 'fhir_search_parameters'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-TERM-001',
            framework: 'hl7_fhir',
            title: 'Terminology Services',
            description: 'CodeSystem interface for SNOMED-CT, LOINC, ICD-10 with lookup and '
                . 'validation. ValueSet validation for bound coded elements. ConceptMap for '
                . 'code translation between systems.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_terminology', 'fhir_code_systems', 'fhir_value_sets', 'fhir_concept_maps'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-SMART-001',
            framework: 'hl7_fhir',
            title: 'SMART on FHIR',
            description: 'SMART App Launch support extending OAuth2 with FHIR-specific scoping '
                . '(patient/*.read, user/*.write, etc.). Scope parsing, enforcement, and '
                . 'well-known SMART configuration endpoint.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_smart_launch', 'fhir_smart_scopes', 'fhir_smart_configuration'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-AUDIT-001',
            framework: 'hl7_fhir',
            title: 'FHIR AuditEvent',
            description: 'Mapping from Pulsar AuditEntry to FHIR AuditEvent resource format. '
                . 'Export capability for FHIR-conformant audit logs as AuditEvent bundles.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_audit_event', 'fhir_audit_export'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-VAL-001',
            framework: 'hl7_fhir',
            title: 'Healthcare Validation Rules',
            description: 'FHIR-aware validation rules: FhirResourceId, Hl7Date, MRN, NPI format '
                . 'validators for healthcare data integrity.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['fhir_resource_id_validation', 'hl7_date_validation', 'mrn_validation', 'npi_validation'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-SEC-001',
            framework: 'hl7_fhir',
            title: 'FHIR Security Labels',
            description: 'Support for security labels on resources via Meta.security coding. '
                . 'Enables data classification and access control based on sensitivity tags.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['fhir_security_labels', 'data_classification'],
        ));

        $catalog->register(new Control(
            id: 'FHIR-PROF-001',
            framework: 'hl7_fhir',
            title: 'FHIR Profiles & Conformance',
            description: 'Infrastructure for profile-based resource validation. Meta.profile '
                . 'support for declaring conformance to implementation guides.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['fhir_profiles', 'fhir_conformance'],
        ));
    }
}
