<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Enum;

use BackedEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

#[CoversClass(AiModelRiskLevel::class)]
#[CoversClass(AiModelStatus::class)]
#[CoversClass(ImpactCategory::class)]
#[CoversClass(ImpactSeverity::class)]
#[CoversClass(AiAuditEvent::class)]
final class AiGovernanceEnumTest extends TestCase
{
    #[Test]
    public function aiModelRiskLevelCases(): void
    {
        $cases = AiModelRiskLevel::cases();

        self::assertCount(4, $cases);
        self::assertSame('minimal', AiModelRiskLevel::Minimal->value);
        self::assertSame('limited', AiModelRiskLevel::Limited->value);
        self::assertSame('high', AiModelRiskLevel::High->value);
        self::assertSame('unacceptable', AiModelRiskLevel::Unacceptable->value);
    }

    #[Test]
    public function aiModelStatusCases(): void
    {
        $cases = AiModelStatus::cases();

        self::assertCount(6, $cases);
        self::assertSame('development', AiModelStatus::Development->value);
        self::assertSame('testing', AiModelStatus::Testing->value);
        self::assertSame('staging', AiModelStatus::Staging->value);
        self::assertSame('production', AiModelStatus::Production->value);
        self::assertSame('deprecated', AiModelStatus::Deprecated->value);
        self::assertSame('retired', AiModelStatus::Retired->value);
    }

    #[Test]
    public function impactCategoryCases(): void
    {
        $cases = ImpactCategory::cases();

        self::assertCount(6, $cases);
        self::assertSame('fairness', ImpactCategory::Fairness->value);
        self::assertSame('transparency', ImpactCategory::Transparency->value);
        self::assertSame('accountability', ImpactCategory::Accountability->value);
        self::assertSame('privacy', ImpactCategory::Privacy->value);
        self::assertSame('safety', ImpactCategory::Safety->value);
        self::assertSame('security', ImpactCategory::Security->value);
    }

    #[Test]
    public function impactSeverityCases(): void
    {
        $cases = ImpactSeverity::cases();

        self::assertCount(4, $cases);
        self::assertSame('low', ImpactSeverity::Low->value);
        self::assertSame('medium', ImpactSeverity::Medium->value);
        self::assertSame('high', ImpactSeverity::High->value);
        self::assertSame('critical', ImpactSeverity::Critical->value);
    }

    /**
     * @return iterable<string, array{class-string<BackedEnum>, string}>
     */
    public static function allEnumsFromProvider(): iterable
    {
        yield 'AiModelRiskLevel::High' => [AiModelRiskLevel::class, 'high'];
        yield 'AiModelStatus::Production' => [AiModelStatus::class, 'production'];
        yield 'ImpactCategory::Privacy' => [ImpactCategory::class, 'privacy'];
        yield 'ImpactSeverity::Critical' => [ImpactSeverity::class, 'critical'];
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     */
    #[Test]
    #[DataProvider('allEnumsFromProvider')]
    public function allEnumsCanBeInstantiatedFromValue(string $enumClass, string $value): void
    {
        $instance = $enumClass::from($value);

        self::assertSame($value, $instance->value);
    }
}
