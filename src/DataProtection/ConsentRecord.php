<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;

/**
 * Immutable value object representing a consent record for a data subject.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConsentRecord implements ConsentRecordInterface
{
    public function __construct(
        private string $subjectId,
        private string $purpose,
        private bool $granted,
        private DateTimeImmutable $recordedAt,
        private string $policyVersion = '',
    ) {}

    #[Override]
    public function subjectId(): string
    {
        return $this->subjectId;
    }

    #[Override]
    public function purpose(): string
    {
        return $this->purpose;
    }

    #[Override]
    public function isGranted(): bool
    {
        return $this->granted;
    }

    #[Override]
    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    #[Override]
    public function policyVersion(): string
    {
        return $this->policyVersion;
    }
}
