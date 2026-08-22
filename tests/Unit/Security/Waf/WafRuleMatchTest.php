<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Waf\WafAction;
use Pulsar\Security\Waf\WafOperator;
use Pulsar\Security\Waf\WafRule;
use Pulsar\Security\Waf\WafRuleMatch;
use Pulsar\Security\Waf\WafSeverity;
use Pulsar\Security\Waf\WafTarget;

#[CoversClass(WafRuleMatch::class)]
final class WafRuleMatchTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $rule = new WafRule(
            id: '942100',
            message: 'SQL Injection Attack Detected',
            targets: [WafTarget::Args],
            operator: WafOperator::Regex,
            pattern: '/union\s+select/i',
            action: WafAction::Block,
            severity: WafSeverity::Critical,
        );

        $match = new WafRuleMatch(
            rule: $rule,
            matchedTarget: WafTarget::Args,
            matchedValue: "' UNION SELECT * FROM users--",
        );

        self::assertSame($rule, $match->rule);
        self::assertSame(WafTarget::Args, $match->matchedTarget);
        self::assertSame("' UNION SELECT * FROM users--", $match->matchedValue);
    }

    #[Test]
    public function matchPreservesRuleMetadata(): void
    {
        $rule = new WafRule(
            id: '941100',
            message: 'XSS Attack Detected',
            targets: [WafTarget::Body, WafTarget::Args],
            operator: WafOperator::Regex,
            pattern: '/<script>/i',
            action: WafAction::Block,
            severity: WafSeverity::Error,
            paranoiaLevel: 2,
        );

        $match = new WafRuleMatch(
            rule: $rule,
            matchedTarget: WafTarget::Body,
            matchedValue: '<script>alert(1)</script>',
        );

        self::assertSame('941100', $match->rule->id);
        self::assertSame('XSS Attack Detected', $match->rule->message);
        self::assertSame(WafAction::Block, $match->rule->action);
        self::assertSame(WafSeverity::Error, $match->rule->severity);
        self::assertSame(2, $match->rule->paranoiaLevel);
    }

    #[Test]
    public function matchWithDifferentTargets(): void
    {
        $rule = new WafRule(
            id: '920420',
            message: 'Method Not Allowed',
            targets: [WafTarget::Method],
            operator: WafOperator::Equals,
            pattern: 'TRACE',
            action: WafAction::Block,
            severity: WafSeverity::Warning,
        );

        $match = new WafRuleMatch($rule, WafTarget::Method, 'TRACE');

        self::assertSame(WafTarget::Method, $match->matchedTarget);
        self::assertSame('TRACE', $match->matchedValue);
    }

    #[Test]
    public function matchWithEmptyValue(): void
    {
        $rule = new WafRule(
            id: '949110',
            message: 'Anomaly Score Exceeded',
            targets: [WafTarget::Headers],
            operator: WafOperator::Regex,
            pattern: '.',
            action: WafAction::Log,
            severity: WafSeverity::Notice,
        );

        $match = new WafRuleMatch($rule, WafTarget::Headers, '');

        self::assertSame('', $match->matchedValue);
    }

    #[Test]
    public function matchWithCookieTarget(): void
    {
        $rule = new WafRule(
            id: '942500',
            message: 'Cookie Injection',
            targets: [WafTarget::Cookies],
            operator: WafOperator::Regex,
            pattern: '/;.*=/',
            action: WafAction::Block,
            severity: WafSeverity::Error,
        );

        $match = new WafRuleMatch($rule, WafTarget::Cookies, 'session=abc; admin=true');

        self::assertSame(WafTarget::Cookies, $match->matchedTarget);
        self::assertStringContainsString('admin=true', $match->matchedValue);
    }
}
