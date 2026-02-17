<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\RedactionRule;
use Pulsar\Api\Resource\RedactionStrategy;
use Pulsar\Security\Compliance\DataClassification;

use function strlen;

#[CoversClass(RedactionRule::class)]
final class RedactionRuleTest extends TestCase
{
    #[Test]
    public function maskStrategyReturnsFixedMask(): void
    {
        $rule = new RedactionRule(
            appliesAbove: DataClassification::Internal,
            strategy: RedactionStrategy::Mask,
            maskChar: '*',
        );

        $result = $rule->apply('sensitive-data');

        self::assertSame('***', $result);
    }

    #[Test]
    public function maskStrategyUsesCustomChar(): void
    {
        $rule = new RedactionRule(
            appliesAbove: DataClassification::Internal,
            strategy: RedactionStrategy::Mask,
            maskChar: 'X',
        );

        $result = $rule->apply('secret');

        self::assertSame('XXX', $result);
    }

    #[Test]
    public function truncateStrategyShowsFirstNChars(): void
    {
        $rule = new RedactionRule(
            appliesAbove: DataClassification::Internal,
            strategy: RedactionStrategy::Truncate,
            truncateLength: 4,
        );

        $result = $rule->apply('john@example.com');

        self::assertSame('john...', $result);
    }

    #[Test]
    public function truncateWithShortValue(): void
    {
        $rule = new RedactionRule(
            appliesAbove: DataClassification::Internal,
            strategy: RedactionStrategy::Truncate,
            truncateLength: 10,
        );

        $result = $rule->apply('hi');

        self::assertSame('hi...', $result);
    }

    #[Test]
    public function hashStrategyReturnsOneWayHash(): void
    {
        $rule = new RedactionRule(
            appliesAbove: DataClassification::Internal,
            strategy: RedactionStrategy::Hash,
            hashAlgorithm: 'sha256',
        );

        $result = $rule->apply('test-value');

        self::assertNotNull($result);
        self::assertNotSame('test-value', $result);
        self::assertSame(64, strlen($result));
        self::assertSame($result, $rule->apply('test-value'));
    }

    #[Test]
    public function omitStrategyReturnsNull(): void
    {
        $rule = new RedactionRule(
            appliesAbove: DataClassification::Internal,
            strategy: RedactionStrategy::Omit,
        );

        $result = $rule->apply('some value');

        self::assertNull($result);
    }

    #[Test]
    public function defaultValues(): void
    {
        $rule = new RedactionRule(
            appliesAbove: DataClassification::Public,
            strategy: RedactionStrategy::Mask,
        );

        self::assertSame('*', $rule->maskChar);
        self::assertSame(4, $rule->truncateLength);
        self::assertSame('sha256', $rule->hashAlgorithm);
    }
}
