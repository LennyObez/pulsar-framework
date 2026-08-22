<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Waf\OwaspCoreRuleSet;
use Pulsar\Security\Waf\WafRule;

#[CoversClass(OwaspCoreRuleSet::class)]
#[CoversClass(WafRule::class)]
final class OwaspCoreRuleSetTest extends TestCase
{
    public function testReturnsNonEmptyRuleSet(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        self::assertNotEmpty($rules);
    }

    public function testAllRulesHaveUniqueIds(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        $ids = array_map(static fn(WafRule $r): string => $r->id, $rules);

        self::assertSame($ids, array_unique($ids), 'Duplicate rule IDs found');
    }

    public function testAllRulesHaveValidParanoiaLevel(): void
    {
        $rules = OwaspCoreRuleSet::rules();

        foreach ($rules as $rule) {
            self::assertGreaterThanOrEqual(1, $rule->paranoiaLevel, "Rule {$rule->id} has invalid paranoia level");
            self::assertLessThanOrEqual(4, $rule->paranoiaLevel, "Rule {$rule->id} has invalid paranoia level");
        }
    }

    public function testAllRulesHaveTargets(): void
    {
        $rules = OwaspCoreRuleSet::rules();

        foreach ($rules as $rule) {
            self::assertNotEmpty($rule->targets, "Rule {$rule->id} has no targets");
        }
    }

    public function testContainsSqliRules(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        $sqliRules = array_filter($rules, static fn(WafRule $r): bool => str_starts_with($r->id, '942'));

        self::assertNotEmpty($sqliRules, 'No SQLi rules found');
    }

    public function testContainsXssRules(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        $xssRules = array_filter($rules, static fn(WafRule $r): bool => str_starts_with($r->id, '941'));

        self::assertNotEmpty($xssRules, 'No XSS rules found');
    }

    public function testContainsPathTraversalRules(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        $traversalRules = array_filter($rules, static fn(WafRule $r): bool => str_starts_with($r->id, '930'));

        self::assertNotEmpty($traversalRules, 'No path traversal rules found');
    }

    public function testContainsCommandInjectionRules(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        $cmdRules = array_filter($rules, static fn(WafRule $r): bool => str_starts_with($r->id, '932'));

        self::assertNotEmpty($cmdRules, 'No command injection rules found');
    }

    public function testContainsRfiLfiRules(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        $rfiRules = array_filter($rules, static fn(WafRule $r): bool => str_starts_with($r->id, '931'));

        self::assertNotEmpty($rfiRules, 'No RFI/LFI rules found');
    }

    public function testContainsProtocolViolationRules(): void
    {
        $rules = OwaspCoreRuleSet::rules();
        $protocolRules = array_filter($rules, static fn(WafRule $r): bool => str_starts_with($r->id, '920'));

        self::assertNotEmpty($protocolRules, 'No protocol violation rules found');
    }
}
