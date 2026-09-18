<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the HL7 FHIR conformance controls Pulsar can be assessed against.
 *
 * Conformance to a FHIR version is established by running a conformance suite
 * against the deployed server, not by inspecting the running process, so most of
 * these name that report as the artefact. The two that ARE observable at runtime
 * — the audit trail and the enforcement of security labels — are probed.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class Hl7FhirMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-RES-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'FHIR Resource Types',
                requirement: 'A FHIR server shall support the resource types it declares in its '
                    . 'CapabilityStatement, conforming to the resource definitions of the '
                    . 'stated FHIR version.',
                artefact: 'The FHIR conformance test report for the deployed version, showing the '
                    . 'declared CapabilityStatement and the results of the conformance suite '
                    . 'run against it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-REST-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'FHIR RESTful API',
                requirement: 'A FHIR server shall implement the RESTful interactions it declares, with '
                    . 'the media types and status codes the specification requires.',
                artefact: 'The FHIR conformance test report for the deployed version, showing the '
                    . 'declared CapabilityStatement and the results of the conformance suite '
                    . 'run against it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-BUNDLE-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'Bundle Support',
                requirement: 'A FHIR server shall process Bundle resources for the batch and '
                    . 'transaction interactions it declares support for.',
                artefact: 'The FHIR conformance test report for the deployed version, showing the '
                    . 'declared CapabilityStatement and the results of the conformance suite '
                    . 'run against it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-SEARCH-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'FHIR Search Parameters',
                requirement: 'A FHIR server shall support the search parameters it declares for each '
                    . 'resource type, with the comparators and modifiers the specification '
                    . 'defines.',
                artefact: 'The FHIR conformance test report for the deployed version, showing the '
                    . 'declared CapabilityStatement and the results of the conformance suite '
                    . 'run against it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-TERM-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'Terminology Services',
                requirement: 'A FHIR server shall support code system lookup, value set validation and '
                    . 'concept map translation for the terminologies it declares.',
                artefact: 'The FHIR conformance test report for the deployed version, showing the '
                    . 'declared CapabilityStatement and the results of the conformance suite '
                    . 'run against it.',
            ),

            // SMART scopes are enforced by a FHIR server against the scopes granted
            // at authorization. Nothing in this repository implements SMART, and the
            // control was decided by whether routes classified as handling regulated
            // data carry their required middleware — a real check, of a mechanism
            // that knows nothing about FHIR scopes.
            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-SMART-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'SMART on FHIR',
                requirement: 'A SMART on FHIR server shall enforce the scopes granted at authorization '
                    . 'on every subsequent request, restricting access to the resources those '
                    . 'scopes permit.',
                artefact: 'The SMART on FHIR conformance statement for the server, with the '
                    . 'scope-enforcement test results showing a request outside its granted '
                    . 'scopes refused.',
            ),

            ControlDeclaration::probed(
                id: 'FHIR-AUDIT-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'FHIR AuditEvent',
                requirement: 'A FHIR server shall record an AuditEvent for the security-relevant '
                    . 'interactions the implementation guide requires, and retain them so they '
                    . 'can be examined.',
                probe: new TamperEvidentAuditProbe(),
                subject: ControlSubject::AuditTrail,
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-VAL-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'Healthcare Validation Rules',
                requirement: 'A FHIR server shall validate submitted resources against the definitions '
                    . 'and invariants of the FHIR version it declares.',
                artefact: 'The FHIR conformance test report for the deployed version, showing the '
                    . 'declared CapabilityStatement and the results of the conformance suite '
                    . 'run against it.',
            ),

            // Security labels live in a resource's Meta.security and are honoured by
            // the FHIR server on read and write. Route middleware coverage cannot
            // see a resource, let alone its labels.
            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-SEC-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'FHIR Security Labels',
                requirement: 'A FHIR server shall honour the security labels carried in Meta.security '
                    . 'when deciding whether a request may read or write a resource.',
                artefact: 'The security-label policy the server enforces, with test results '
                    . 'showing a labelled resource refused to a request that lacks the '
                    . 'corresponding clearance.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'FHIR-PROF-001',
                framework: ComplianceFramework::Hl7Fhir,
                title: 'FHIR Profiles & Conformance',
                requirement: 'A FHIR server shall validate resources against the profiles declared in '
                    . 'Meta.profile and in its CapabilityStatement.',
                artefact: 'The FHIR conformance test report for the deployed version, showing the '
                    . 'declared CapabilityStatement and the results of the conformance suite '
                    . 'run against it.',
            ),
        ];
    }
}
