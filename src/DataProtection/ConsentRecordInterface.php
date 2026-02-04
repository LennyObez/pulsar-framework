<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Represents a single consent record for a data subject.
 *
 * Consent records capture the fact that a subject (identified by an opaque
 * string) granted or revoked consent for a specific purpose at a given time.
 * Implementations should be immutable value objects.
 */
#[Api(since: '1.0.0')]
interface ConsentRecordInterface
{
    /**
     * Get the opaque identifier of the data subject (user, patient, etc.).
     */
    public function subjectId(): string;

    /**
     * Get the purpose for which consent was given.
     *
     * Purposes are application-defined strings (e.g. "marketing_email",
     * "analytics", "data_sharing_third_party").
     */
    public function purpose(): string;

    /**
     * Whether consent is currently granted.
     *
     * Returns false if consent was revoked or never granted.
     */
    public function isGranted(): bool;

    /**
     * Get the timestamp when this consent state was recorded.
     */
    public function recordedAt(): DateTimeImmutable;

    /**
     * Get the version of the privacy policy or terms the subject consented to.
     *
     * Returns an empty string if version tracking is not applicable.
     */
    public function policyVersion(): string;
}
