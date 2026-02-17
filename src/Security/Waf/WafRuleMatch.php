<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Pulsar\Api\Api;

/**
 * Result of a WAF rule matching against a request.
 */
#[Api(since: '1.0.0')]
final readonly class WafRuleMatch
{
    public function __construct(
        public WafRule $rule,
        public WafTarget $matchedTarget,
        public string $matchedValue,
    ) {}
}
