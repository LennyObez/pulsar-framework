<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Security\SensitiveOperation;
use Pulsar\Auth\Security\TakeoverRisk;
use Pulsar\Auth\Security\TakeoverRiskLevel;

#[CoversClass(TakeoverRisk::class)]
#[CoversClass(TakeoverRiskLevel::class)]
#[CoversClass(SensitiveOperation::class)]
final class TakeoverRiskTest extends TestCase
{
    // ── TakeoverRisk ────────────────────────────────────────────────

    #[Test]
    public function lowFactoryCreatesLowRisk(): void
    {
        $risk = TakeoverRisk::low();

        self::assertSame(TakeoverRiskLevel::Low, $risk->level);
        self::assertSame('', $risk->reason);
        self::assertTrue($risk->isLow());
        self::assertFalse($risk->isElevatedOrHigher());
    }

    #[Test]
    public function elevatedFactoryCreatesElevatedRisk(): void
    {
        $risk = TakeoverRisk::elevated('IP address changed');

        self::assertSame(TakeoverRiskLevel::Elevated, $risk->level);
        self::assertSame('IP address changed', $risk->reason);
        self::assertFalse($risk->isLow());
        self::assertTrue($risk->isElevatedOrHigher());
    }

    #[Test]
    public function highFactoryCreatesHighRisk(): void
    {
        $risk = TakeoverRisk::high('IP + device changed simultaneously');

        self::assertSame(TakeoverRiskLevel::High, $risk->level);
        self::assertSame('IP + device changed simultaneously', $risk->reason);
        self::assertFalse($risk->isLow());
        self::assertTrue($risk->isElevatedOrHigher());
    }

    // ── TakeoverRiskLevel ───────────────────────────────────────────

    #[Test]
    public function riskLevelHasThreeCases(): void
    {
        self::assertCount(3, TakeoverRiskLevel::cases());
    }

    #[Test]
    #[DataProvider('riskLevelProvider')]
    public function riskLevelBackedValues(TakeoverRiskLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->value);
    }

    /**
     * @return iterable<string, array{TakeoverRiskLevel, string}>
     */
    public static function riskLevelProvider(): iterable
    {
        yield 'Low' => [TakeoverRiskLevel::Low, 'low'];
        yield 'Elevated' => [TakeoverRiskLevel::Elevated, 'elevated'];
        yield 'High' => [TakeoverRiskLevel::High, 'high'];
    }

    #[Test]
    public function riskLevelFromBackedValue(): void
    {
        self::assertSame(TakeoverRiskLevel::Low, TakeoverRiskLevel::from('low'));
        self::assertSame(TakeoverRiskLevel::Elevated, TakeoverRiskLevel::from('elevated'));
        self::assertSame(TakeoverRiskLevel::High, TakeoverRiskLevel::from('high'));
    }

    // ── SensitiveOperation ──────────────────────────────────────────

    #[Test]
    public function sensitiveOperationHasSixCases(): void
    {
        self::assertCount(6, SensitiveOperation::cases());
    }

    #[Test]
    #[DataProvider('sensitiveOperationProvider')]
    public function sensitiveOperationBackedValues(SensitiveOperation $op, string $expected): void
    {
        self::assertSame($expected, $op->value);
    }

    /**
     * @return iterable<string, array{SensitiveOperation, string}>
     */
    public static function sensitiveOperationProvider(): iterable
    {
        yield 'PasswordChange' => [SensitiveOperation::PasswordChange, 'password_change'];
        yield 'EmailChange' => [SensitiveOperation::EmailChange, 'email_change'];
        yield 'MfaDisable' => [SensitiveOperation::MfaDisable, 'mfa_disable'];
        yield 'RecoveryCodeRegenerate' => [SensitiveOperation::RecoveryCodeRegenerate, 'recovery_code_regenerate'];
        yield 'AccountDelete' => [SensitiveOperation::AccountDelete, 'account_delete'];
        yield 'ApiKeyCreate' => [SensitiveOperation::ApiKeyCreate, 'api_key_create'];
    }

    #[Test]
    public function sensitiveOperationFromBackedValue(): void
    {
        self::assertSame(SensitiveOperation::PasswordChange, SensitiveOperation::from('password_change'));
        self::assertSame(SensitiveOperation::AccountDelete, SensitiveOperation::from('account_delete'));
    }
}
