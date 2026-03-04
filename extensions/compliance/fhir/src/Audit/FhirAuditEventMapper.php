<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Audit;

use Pulsar\Api\Api;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent as PulsarAuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;

/**
 * Maps Pulsar AuditEntry instances to FHIR AuditEvent resource format.
 *
 * This enables exporting Pulsar audit logs as FHIR-conformant AuditEvent
 * resources for interoperability with healthcare systems.
 *
 * @see https://www.hl7.org/fhir/auditevent.html
 */
#[Api(since: '1.0.0')]
final readonly class FhirAuditEventMapper
{
    private const string DCM_SYSTEM = 'http://dicom.nema.org/resources/ontology/DCM';
    private const string AUDIT_EVENT_TYPE_SYSTEM = 'http://terminology.hl7.org/CodeSystem/audit-event-type';

    /**
     * Convert a Pulsar AuditEntry to a FHIR AuditEvent array.
     *
     * @return array<string, mixed>
     */
    public function toFhirAuditEvent(AuditEntry $entry): array
    {
        return [
            'resourceType' => 'AuditEvent',
            'id' => $entry->id,
            'type' => $this->mapEventType($entry->event)->toArray(),
            'subtype' => [$this->mapSubtype($entry->action)->toArray()],
            'action' => $this->mapAction($entry->action),
            'recorded' => $entry->timestamp->format('Y-m-d\TH:i:s.vP'),
            'outcome' => $this->mapOutcome($entry->outcome),
            'agent' => [
                [
                    'who' => [
                        'display' => $entry->actor,
                    ],
                    'requestor' => true,
                ],
            ],
            'source' => [
                'observer' => [
                    'display' => 'Pulsar Framework',
                ],
            ],
            'entity' => $entry->resource !== '' ? [
                [
                    'what' => [
                        'display' => $entry->resource,
                    ],
                ],
            ] : [],
        ];
    }

    /**
     * Convert multiple Pulsar AuditEntry instances to a FHIR Bundle of AuditEvents.
     *
     * @param list<AuditEntry> $entries
     *
     * @return array<string, mixed>
     */
    public function toBundleArray(array $entries): array
    {
        $bundleEntries = [];

        foreach ($entries as $entry) {
            $bundleEntries[] = [
                'fullUrl' => "AuditEvent/{$entry->id}",
                'resource' => $this->toFhirAuditEvent($entry),
            ];
        }

        return [
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'total' => count($entries),
            'entry' => $bundleEntries,
        ];
    }

    private function mapEventType(PulsarAuditEvent $event): Coding
    {
        $code = match ($event) {
            PulsarAuditEvent::Authentication => '110114',
            PulsarAuditEvent::Authorization => '110114',
            PulsarAuditEvent::DataAccess => '110110',
            PulsarAuditEvent::DataModification => '110112',
            PulsarAuditEvent::ConfigurationChange => '110113',
            PulsarAuditEvent::SecurityEvent => '110127',
            PulsarAuditEvent::SystemEvent => '110100',
            PulsarAuditEvent::SchemaModification => '110113',
            PulsarAuditEvent::Communication => '110111',
        };

        $display = match ($event) {
            PulsarAuditEvent::Authentication => 'User Authentication',
            PulsarAuditEvent::Authorization => 'User Authentication',
            PulsarAuditEvent::DataAccess => 'Audit Log Used',
            PulsarAuditEvent::DataModification => 'Data Import',
            PulsarAuditEvent::ConfigurationChange => 'Security Configuration',
            PulsarAuditEvent::SecurityEvent => 'Emergency Override Started',
            PulsarAuditEvent::SystemEvent => 'Application Activity',
            PulsarAuditEvent::SchemaModification => 'Security Configuration',
            PulsarAuditEvent::Communication => 'Communication',
        };

        return new Coding(
            system: self::DCM_SYSTEM,
            code: $code,
            display: $display,
        );
    }

    private function mapSubtype(string $action): Coding
    {
        return new Coding(
            system: self::AUDIT_EVENT_TYPE_SYSTEM,
            code: $action,
            display: $action,
        );
    }

    private function mapAction(string $action): string
    {
        // Map to FHIR AuditEvent action codes: C (Create), R (Read), U (Update), D (Delete), E (Execute)
        $lower = strtolower($action);

        if (str_contains($lower, 'create') || str_contains($lower, 'add') || str_contains($lower, 'register')) {
            return 'C';
        }

        if (str_contains($lower, 'read') || str_contains($lower, 'view') || str_contains($lower, 'get') || str_contains($lower, 'list')) {
            return 'R';
        }

        if (str_contains($lower, 'update') || str_contains($lower, 'modify') || str_contains($lower, 'change')) {
            return 'U';
        }

        if (str_contains($lower, 'delete') || str_contains($lower, 'remove') || str_contains($lower, 'revoke')) {
            return 'D';
        }

        return 'E'; // Execute for anything else
    }

    private function mapOutcome(AuditOutcome $outcome): string
    {
        // FHIR AuditEvent outcome: 0 (Success), 4 (Minor failure), 8 (Serious failure), 12 (Major failure)
        return match ($outcome) {
            AuditOutcome::Success => '0',
            AuditOutcome::Failure => '8',
            AuditOutcome::Denied => '4',
            AuditOutcome::Error => '12',
        };
    }
}
