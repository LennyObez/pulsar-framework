<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Pulsar\Api\Api;

/**
 * Individual WAF rule definition.
 *
 * Inspired by ModSecurity SecRule format but expressed as PHP-native DTOs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WafRule
{
    /**
     * @param string $id Unique rule identifier (e.g. "942100" for SQLi)
     * @param string $message Human-readable description
     * @param list<WafTarget> $targets Request components to inspect
     * @param WafOperator $operator Matching operator
     * @param string $pattern Pattern or keyword for the operator
     * @param WafAction $action Action to take on match
     * @param WafSeverity $severity Rule severity
     * @param int $paranoiaLevel Minimum paranoia level (1-4) for this rule to be active
     */
    public function __construct(
        public string $id,
        public string $message,
        public array $targets,
        public WafOperator $operator,
        public string $pattern,
        public WafAction $action,
        public WafSeverity $severity,
        public int $paranoiaLevel = 1,
    ) {}
}
