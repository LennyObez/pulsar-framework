<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Waf\WafAction;
use Pulsar\Security\Waf\WafOperator;
use Pulsar\Security\Waf\WafRule;
use Pulsar\Security\Waf\WafRuleMatch;
use Pulsar\Security\Waf\WafSeverity;
use Pulsar\Security\Waf\WafTarget;

#[CoversClass(WafRuleMatch::class)]
final class WafEnumTest extends TestCase
{
    // ── WafAction ───────────────────────────────────────────────────

    #[Test]
    public function wafActionHasFourCases(): void
    {
        self::assertCount(4, WafAction::cases());
    }

    #[Test]
    #[DataProvider('wafActionProvider')]
    public function wafActionBackedValues(WafAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    /**
     * @return iterable<string, array{WafAction, string}>
     */
    public static function wafActionProvider(): iterable
    {
        yield 'Block' => [WafAction::Block, 'block'];
        yield 'Log' => [WafAction::Log, 'log'];
        yield 'Alert' => [WafAction::Alert, 'alert'];
        yield 'Challenge' => [WafAction::Challenge, 'challenge'];
    }

    // ── WafSeverity ─────────────────────────────────────────────────

    #[Test]
    public function wafSeverityHasFourCases(): void
    {
        self::assertCount(4, WafSeverity::cases());
    }

    #[Test]
    #[DataProvider('wafSeverityProvider')]
    public function wafSeverityBackedValues(WafSeverity $severity, int $expected): void
    {
        self::assertSame($expected, $severity->value);
    }

    /**
     * @return iterable<string, array{WafSeverity, int}>
     */
    public static function wafSeverityProvider(): iterable
    {
        yield 'Critical' => [WafSeverity::Critical, 2];
        yield 'Error' => [WafSeverity::Error, 3];
        yield 'Warning' => [WafSeverity::Warning, 4];
        yield 'Notice' => [WafSeverity::Notice, 5];
    }

    #[Test]
    public function criticalIsMoreSevereThanNotice(): void
    {
        self::assertLessThan(WafSeverity::Notice->value, WafSeverity::Critical->value);
    }

    // ── WafOperator ─────────────────────────────────────────────────

    #[Test]
    public function wafOperatorHasSevenCases(): void
    {
        self::assertCount(7, WafOperator::cases());
    }

    #[Test]
    #[DataProvider('wafOperatorProvider')]
    public function wafOperatorBackedValues(WafOperator $op, string $expected): void
    {
        self::assertSame($expected, $op->value);
    }

    /**
     * @return iterable<string, array{WafOperator, string}>
     */
    public static function wafOperatorProvider(): iterable
    {
        yield 'Contains' => [WafOperator::Contains, 'contains'];
        yield 'Regex' => [WafOperator::Regex, 'regex'];
        yield 'BeginsWith' => [WafOperator::BeginsWith, 'beginsWith'];
        yield 'EndsWith' => [WafOperator::EndsWith, 'endsWith'];
        yield 'DetectSqli' => [WafOperator::DetectSqli, 'detectSQLi'];
        yield 'DetectXss' => [WafOperator::DetectXss, 'detectXSS'];
        yield 'Equals' => [WafOperator::Equals, 'equals'];
    }

    // ── WafTarget ───────────────────────────────────────────────────

    #[Test]
    public function wafTargetHasSevenCases(): void
    {
        self::assertCount(7, WafTarget::cases());
    }

    #[Test]
    #[DataProvider('wafTargetProvider')]
    public function wafTargetBackedValues(WafTarget $target, string $expected): void
    {
        self::assertSame($expected, $target->value);
    }

    /**
     * @return iterable<string, array{WafTarget, string}>
     */
    public static function wafTargetProvider(): iterable
    {
        yield 'Args' => [WafTarget::Args, 'ARGS'];
        yield 'Headers' => [WafTarget::Headers, 'HEADERS'];
        yield 'Body' => [WafTarget::Body, 'BODY'];
        yield 'Uri' => [WafTarget::Uri, 'URI'];
        yield 'Cookies' => [WafTarget::Cookies, 'COOKIES'];
        yield 'UserAgent' => [WafTarget::UserAgent, 'USER_AGENT'];
        yield 'Method' => [WafTarget::Method, 'METHOD'];
    }

    // ── WafRuleMatch ────────────────────────────────────────────────

    #[Test]
    public function wafRuleMatchStoresProperties(): void
    {
        $rule = new WafRule(
            id: '942100',
            message: 'SQL Injection detected',
            targets: [WafTarget::Args, WafTarget::Body],
            operator: WafOperator::DetectSqli,
            pattern: '',
            action: WafAction::Block,
            severity: WafSeverity::Critical,
        );

        $match = new WafRuleMatch(
            rule: $rule,
            matchedTarget: WafTarget::Args,
            matchedValue: "' OR 1=1 --",
        );

        self::assertSame($rule, $match->rule);
        self::assertSame(WafTarget::Args, $match->matchedTarget);
        self::assertSame("' OR 1=1 --", $match->matchedValue);
    }

    #[Test]
    public function wafRuleMatchPreservesRuleProperties(): void
    {
        $rule = new WafRule(
            id: '941100',
            message: 'XSS detected',
            targets: [WafTarget::Args],
            operator: WafOperator::DetectXss,
            pattern: '',
            action: WafAction::Alert,
            severity: WafSeverity::Warning,
            paranoiaLevel: 2,
        );

        $match = new WafRuleMatch($rule, WafTarget::Args, '<script>');

        self::assertSame('941100', $match->rule->id);
        self::assertSame(WafAction::Alert, $match->rule->action);
        self::assertSame(2, $match->rule->paranoiaLevel);
    }
}
