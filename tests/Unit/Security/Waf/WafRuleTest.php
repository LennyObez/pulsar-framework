<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Waf\WafAction;
use Pulsar\Security\Waf\WafOperator;
use Pulsar\Security\Waf\WafRule;
use Pulsar\Security\Waf\WafRuleMatch;
use Pulsar\Security\Waf\WafSeverity;
use Pulsar\Security\Waf\WafTarget;

#[CoversClass(WafRule::class)]
#[CoversClass(WafRuleMatch::class)]
final class WafRuleTest extends TestCase
{
    public function testRuleConstructorAssignsProperties(): void
    {
        $rule = new WafRule(
            id: '942100',
            message: 'SQL injection attempt detected',
            targets: [WafTarget::Args, WafTarget::Body],
            operator: WafOperator::Regex,
            pattern: '/(?:union\s+select|drop\s+table)/i',
            action: WafAction::Block,
            severity: WafSeverity::Critical,
            paranoiaLevel: 2,
        );

        self::assertSame('942100', $rule->id);
        self::assertSame('SQL injection attempt detected', $rule->message);
        self::assertCount(2, $rule->targets);
        self::assertSame(WafOperator::Regex, $rule->operator);
        self::assertSame(WafAction::Block, $rule->action);
        self::assertSame(WafSeverity::Critical, $rule->severity);
        self::assertSame(2, $rule->paranoiaLevel);
    }

    public function testRuleDefaultParanoiaLevel(): void
    {
        $rule = new WafRule(
            id: '941100',
            message: 'XSS attempt',
            targets: [WafTarget::Args],
            operator: WafOperator::Contains,
            pattern: '<script',
            action: WafAction::Block,
            severity: WafSeverity::Error,
        );

        self::assertSame(1, $rule->paranoiaLevel);
    }

    public function testRuleMatchAssignsProperties(): void
    {
        $rule = new WafRule(
            id: '942100',
            message: 'SQL injection',
            targets: [WafTarget::Args],
            operator: WafOperator::Regex,
            pattern: '/union select/i',
            action: WafAction::Block,
            severity: WafSeverity::Critical,
        );

        $match = new WafRuleMatch(
            rule: $rule,
            matchedTarget: WafTarget::Args,
            matchedValue: 'union select 1,2,3',
        );

        self::assertSame($rule, $match->rule);
        self::assertSame(WafTarget::Args, $match->matchedTarget);
        self::assertSame('union select 1,2,3', $match->matchedValue);
    }
}
