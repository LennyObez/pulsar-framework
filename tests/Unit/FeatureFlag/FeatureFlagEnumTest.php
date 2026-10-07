<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\FeatureFlag\FlagStorageDriver;
use Pulsar\FeatureFlag\FlagType;

#[CoversNothing]
final class FeatureFlagEnumTest extends TestCase
{
    // ── FlagType ────────────────────────────────────────────────────────

    #[Test]
    public function flagTypeHasThreeCases(): void
    {
        self::assertCount(3, FlagType::cases());
    }

    #[Test]
    #[DataProvider('flagTypeProvider')]
    public function flagTypeBackedValues(FlagType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{FlagType, string}>
     */
    public static function flagTypeProvider(): iterable
    {
        yield 'Boolean' => [FlagType::Boolean, 'boolean'];
        yield 'Percentage' => [FlagType::Percentage, 'percentage'];
        yield 'Contextual' => [FlagType::Contextual, 'contextual'];
    }

    // ── FlagStorageDriver ───────────────────────────────────────────────

    #[Test]
    public function flagStorageDriverHasTwoCases(): void
    {
        self::assertCount(2, FlagStorageDriver::cases());
    }

    #[Test]
    #[DataProvider('flagStorageDriverProvider')]
    public function flagStorageDriverBackedValues(FlagStorageDriver $driver, string $expected): void
    {
        self::assertSame($expected, $driver->value);
    }

    /**
     * @return iterable<string, array{FlagStorageDriver, string}>
     */
    public static function flagStorageDriverProvider(): iterable
    {
        yield 'Memory' => [FlagStorageDriver::Memory, 'memory'];
        yield 'File' => [FlagStorageDriver::File, 'file'];
    }

    // ── FlagEvaluationReason ────────────────────────────────────────────

    #[Test]
    public function flagEvaluationReasonHasNineCases(): void
    {
        self::assertCount(9, FlagEvaluationReason::cases());
    }

    #[Test]
    #[DataProvider('flagEvaluationReasonProvider')]
    public function flagEvaluationReasonBackedValues(FlagEvaluationReason $reason, string $expected): void
    {
        self::assertSame($expected, $reason->value);
    }

    /**
     * @return iterable<string, array{FlagEvaluationReason, string}>
     */
    public static function flagEvaluationReasonProvider(): iterable
    {
        yield 'FlagDisabled' => [FlagEvaluationReason::FlagDisabled, 'flag_disabled'];
        yield 'FlagEnabled' => [FlagEvaluationReason::FlagEnabled, 'flag_enabled'];
        yield 'FlagNotFound' => [FlagEvaluationReason::FlagNotFound, 'flag_not_found'];
        yield 'DefaultState' => [FlagEvaluationReason::DefaultState, 'default_state'];
        yield 'TenantMatch' => [FlagEvaluationReason::TenantMatch, 'tenant_match'];
        yield 'UserMatch' => [FlagEvaluationReason::UserMatch, 'user_match'];
        yield 'EnvironmentMatch' => [FlagEvaluationReason::EnvironmentMatch, 'environment_match'];
        yield 'PercentageRollout' => [FlagEvaluationReason::PercentageRollout, 'percentage_rollout'];
        yield 'PercentageExcluded' => [FlagEvaluationReason::PercentageExcluded, 'percentage_excluded'];
    }

    #[Test]
    public function flagEvaluationReasonFromBackedValue(): void
    {
        self::assertSame(FlagEvaluationReason::TenantMatch, FlagEvaluationReason::from('tenant_match'));
    }
}
