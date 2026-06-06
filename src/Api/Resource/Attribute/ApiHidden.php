<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Explicitly marks a field as hidden from the API response.
 *
 * This attribute is optional since all fields without {@see Expose} are hidden by default.
 * Use it for documentation emphasis on fields that callers might mistakenly expect
 * to be exposed (e.g., passwords, internal IDs, audit columns).
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
#[Api(since: '1.0.0')]
final readonly class ApiHidden
{
    /**
     * @param string $reason Explanation for why this field is hidden
     */
    public function __construct(
        public string $reason = '',
    ) {}
}
