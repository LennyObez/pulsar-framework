<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\SamplerType;

#[CoversClass(SamplerType::class)]
final class SamplerTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('caseProvider')]
    public function backedValues(SamplerType $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{SamplerType, string}>
     */
    public static function caseProvider(): iterable
    {
        yield 'always' => [SamplerType::Always, 'always'];
        yield 'never' => [SamplerType::Never, 'never'];
        yield 'probability' => [SamplerType::Probability, 'probability'];
        yield 'rate_limited' => [SamplerType::RateLimited, 'rate_limited'];
        yield 'parent_based' => [SamplerType::ParentBased, 'parent_based'];
    }

    #[Test]
    public function tryFromValidValue(): void
    {
        self::assertSame(SamplerType::Probability, SamplerType::tryFrom('probability'));
    }

    #[Test]
    public function tryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(SamplerType::tryFrom('random'));
    }

    #[Test]
    public function allCasesAreCovered(): void
    {
        self::assertCount(5, SamplerType::cases());
    }
}
