<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;

/**
 * In-memory consent manager for testing and development.
 *
 * Stores consent records in a PHP array keyed by subject+purpose.
 * All data is lost when the process ends. Production deployments
 * should use a database-backed implementation.
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemoryConsentManager implements ConsentManagerInterface
{
    /** @var array<string, ConsentRecord> keyed by "{subjectId}:{purpose}" */
    private array $records = [];

    #[Override]
    public function grant(string $subjectId, string $purpose, string $policyVersion = ''): ConsentRecordInterface
    {
        $record = new ConsentRecord(
            subjectId: $subjectId,
            purpose: $purpose,
            granted: true,
            recordedAt: new DateTimeImmutable(),
            policyVersion: $policyVersion,
        );

        $this->records[self::key($subjectId, $purpose)] = $record;

        return $record;
    }

    #[Override]
    public function revoke(string $subjectId, string $purpose): ?ConsentRecordInterface
    {
        $key = self::key($subjectId, $purpose);

        if (!isset($this->records[$key])) {
            return null;
        }

        $record = new ConsentRecord(
            subjectId: $subjectId,
            purpose: $purpose,
            granted: false,
            recordedAt: new DateTimeImmutable(),
            policyVersion: $this->records[$key]->policyVersion(),
        );

        $this->records[$key] = $record;

        return $record;
    }

    #[Override]
    public function hasConsent(string $subjectId, string $purpose): bool
    {
        $key = self::key($subjectId, $purpose);

        return isset($this->records[$key]) && $this->records[$key]->isGranted();
    }

    #[Override]
    public function getRecord(string $subjectId, string $purpose): ?ConsentRecordInterface
    {
        return $this->records[self::key($subjectId, $purpose)] ?? null;
    }

    #[Override]
    public function getAllForSubject(string $subjectId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(ConsentRecord $r): bool => $r->subjectId() === $subjectId,
        ));
    }

    private static function key(string $subjectId, string $purpose): string
    {
        return $subjectId . ':' . $purpose;
    }
}
