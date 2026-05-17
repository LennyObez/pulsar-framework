<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Snapshot;

use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\Compliance\Exception\ComplianceException;

/**
 * Captures classified entity snapshots for SOX audit trail controls.
 *
 * Supports controls for SOX Section 302/404 by producing immutable,
 * classification-aware snapshots. Restricted fields are redacted at
 * capture time and public fields are excluded entirely.
 * @api
 */
#[Api(since: '1.0.0')]
final class SnapshotCapture
{
    private function __construct() {}

    /**
     * Capture a point-in-time snapshot of classified entity fields.
     *
     * Restricted fields have their values replaced with '[REDACTED]'.
     * Public fields are excluded from the snapshot entirely.
     * Internal and Confidential fields are captured as-is.
     *
     * @throws ComplianceException When no fields are provided.
     */
    #[NoDiscard]
    public static function capture(
        string $entityType,
        string $entityId,
        ClassifiedField ...$fields,
    ): Snapshot {
        if ($fields === []) {
            throw ComplianceException::snapshotCaptureRefused('no fields provided');
        }

        $capturedFields = [];

        foreach ($fields as $field) {
            if ($field->classification === DataClassification::Public) {
                continue;
            }

            $value = $field->classification === DataClassification::Restricted
                ? '[REDACTED]'
                : $field->value;

            $capturedFields[] = new ClassifiedField(
                name: $field->name,
                value: $value,
                classification: $field->classification,
            );
        }

        return new Snapshot(
            entityType: $entityType,
            entityId: $entityId,
            fields: $capturedFields,
            capturedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /**
     * Create a before/after diff from two snapshots.
     */
    #[NoDiscard]
    public static function diff(Snapshot $before, Snapshot $after): BeforeAfterSnapshot
    {
        return new BeforeAfterSnapshot(
            before: $before,
            after: $after,
        );
    }
}
