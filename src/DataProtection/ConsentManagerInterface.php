<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use Pulsar\Api\Api;

/**
 * Manages consent records for data subjects.
 *
 * Provides operations to record, revoke, and query consent state. All
 * mutations produce an audit trail via the underlying storage mechanism.
 * @api
 */
#[Api(since: '1.0.0')]
interface ConsentManagerInterface
{
    /**
     * Record that a subject has granted consent for a purpose.
     *
     * If consent was already granted for the same subject+purpose, the
     * existing record should be superseded (new timestamp, new policy version).
     *
     * @param string $subjectId     Opaque subject identifier
     * @param string $purpose       Consent purpose
     * @param string $policyVersion Version of the policy the subject agreed to
     */
    public function grant(string $subjectId, string $purpose, string $policyVersion = ''): ConsentRecordInterface;

    /**
     * Revoke a subject's consent for a purpose.
     *
     * If no active consent exists for the given subject+purpose, this is a
     * no-op and returns the most recent record (or null if none exists).
     *
     * @param string $subjectId Opaque subject identifier
     * @param string $purpose   Consent purpose
     */
    public function revoke(string $subjectId, string $purpose): ?ConsentRecordInterface;

    /**
     * Check whether a subject currently has active consent for a purpose.
     *
     * @param string $subjectId Opaque subject identifier
     * @param string $purpose   Consent purpose
     */
    public function hasConsent(string $subjectId, string $purpose): bool;

    /**
     * Get the most recent consent record for a subject and purpose.
     *
     * Returns null if no consent record exists for the given combination.
     *
     * @param string $subjectId Opaque subject identifier
     * @param string $purpose   Consent purpose
     */
    public function getRecord(string $subjectId, string $purpose): ?ConsentRecordInterface;

    /**
     * Get all consent records for a subject across all purposes.
     *
     * Returns an empty array if no records exist for the subject.
     *
     * @param string $subjectId Opaque subject identifier
     *
     * @return list<ConsentRecordInterface>
     */
    public function getAllForSubject(string $subjectId): array;
}
