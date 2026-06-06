<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource\Attribute;

use Attribute;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Tags a resource or field with a data classification level.
 *
 * Uses the existing {@see DataClassification} enum to enforce data handling
 * policies. When a requester's clearance is below the tagged level,
 * fields are either redacted or omitted based on configured rules.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class ClassificationTag
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public DataClassification $level,
    ) {}
}
