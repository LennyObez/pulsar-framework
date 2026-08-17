<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Security;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Security\SensitiveOperation;

#[CoversNothing]
final class SensitiveOperationTest extends TestCase
{
    #[Test]
    public function hasSixCases(): void
    {
        self::assertCount(6, SensitiveOperation::cases());
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function backedValueMatchesExpected(SensitiveOperation $op, string $expected): void
    {
        self::assertSame($expected, $op->value);
    }

    /**
     * @return iterable<string, array{SensitiveOperation, string}>
     */
    public static function operationProvider(): iterable
    {
        yield 'PasswordChange' => [SensitiveOperation::PasswordChange, 'password_change'];
        yield 'EmailChange' => [SensitiveOperation::EmailChange, 'email_change'];
        yield 'MfaDisable' => [SensitiveOperation::MfaDisable, 'mfa_disable'];
        yield 'RecoveryCodeRegenerate' => [SensitiveOperation::RecoveryCodeRegenerate, 'recovery_code_regenerate'];
        yield 'AccountDelete' => [SensitiveOperation::AccountDelete, 'account_delete'];
        yield 'ApiKeyCreate' => [SensitiveOperation::ApiKeyCreate, 'api_key_create'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (SensitiveOperation::cases() as $op) {
            self::assertSame($op, SensitiveOperation::from($op->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(SensitiveOperation::tryFrom('nonexistent'));
    }
}
