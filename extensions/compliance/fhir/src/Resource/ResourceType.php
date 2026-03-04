<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

/**
 * FHIR resource types supported by this extension.
 *
 * @see https://www.hl7.org/fhir/resourcelist.html
 */
#[Api(since: '1.0.0')]
enum ResourceType: string
{
    case Patient = 'Patient';
    case Observation = 'Observation';
    case Encounter = 'Encounter';
    case Condition = 'Condition';
    case MedicationRequest = 'MedicationRequest';
    case AllergyIntolerance = 'AllergyIntolerance';
    case Procedure = 'Procedure';
    case DiagnosticReport = 'DiagnosticReport';
    case Bundle = 'Bundle';
    case CapabilityStatement = 'CapabilityStatement';
    case AuditEvent = 'AuditEvent';
    case OperationOutcome = 'OperationOutcome';
}
