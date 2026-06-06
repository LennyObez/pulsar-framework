<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi\Attribute;

use Attribute;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Annotates a DTO or resource property with compliance metadata.
 *
 * These annotations are emitted as vendor extensions in the generated
 * OpenAPI schema:
 *
 * - `x-pulsar-classification`: data sensitivity level
 * - `x-pulsar-access-level`: minimum role or clearance required
 * - `x-pulsar-redacted`: whether the field may be omitted for unauthorized callers
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
#[Api(since: '1.0.0')]
final readonly class ApiField
{
    /**
     * @param DataClassification $classification Data sensitivity level
     * @param string|null $accessLevel Minimum role or clearance required (e.g., 'admin', 'auditor')
     * @param bool $redacted Whether this field may be omitted for unauthorized callers
     * @param string|null $description Override the field description in the schema
     * @param mixed $example Example value for documentation
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public DataClassification $classification = DataClassification::Internal,
        public ?string $accessLevel = null,
        public bool $redacted = false,
        public ?string $description = null,
        public mixed $example = null,
    ) {}
}
