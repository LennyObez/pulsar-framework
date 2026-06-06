<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource\Attribute;

use Attribute;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Marks a field/property as exposed in the API response.
 *
 * Fields without this attribute are NEVER included in serialized output.
 * This enforces a deny-by-default field exposure policy.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
#[Api(since: '1.0.0')]
final readonly class Expose
{
    /**
     * @param string $as Alias for the field name in the serialized output (defaults to property name)
     * @param DataClassification $classification Data classification level for this field
     * @param list<string> $requiredPermissions Permissions required to see this field
     * @param list<string> $requiredRoles Roles required to see this field (any match grants access)
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $as = '',
        public DataClassification $classification = DataClassification::Public,
        public array $requiredPermissions = [],
        public array $requiredRoles = [],
    ) {}
}
