<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePattern;

use function strlen;

#[CoversClass(SensitivePattern::class)]
final class DlpEnumTest extends TestCase
{
    // ── DlpAction ───────────────────────────────────────────────────

    #[Test]
    public function dlpActionHasThreeCases(): void
    {
        self::assertCount(3, DlpAction::cases());
    }

    #[Test]
    #[DataProvider('dlpActionProvider')]
    public function dlpActionBackedValues(DlpAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    /**
     * @return iterable<string, array{DlpAction, string}>
     */
    public static function dlpActionProvider(): iterable
    {
        yield 'Redact' => [DlpAction::Redact, 'redact'];
        yield 'Block' => [DlpAction::Block, 'block'];
        yield 'Alert' => [DlpAction::Alert, 'alert'];
    }

    #[Test]
    public function dlpActionFromBackedValue(): void
    {
        self::assertSame(DlpAction::Redact, DlpAction::from('redact'));
        self::assertSame(DlpAction::Block, DlpAction::from('block'));
    }

    // ── SensitiveDataType ───────────────────────────────────────────

    #[Test]
    public function sensitiveDataTypeHasSevenCases(): void
    {
        self::assertCount(7, SensitiveDataType::cases());
    }

    #[Test]
    #[DataProvider('sensitiveDataTypeProvider')]
    public function sensitiveDataTypeBackedValues(SensitiveDataType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{SensitiveDataType, string}>
     */
    public static function sensitiveDataTypeProvider(): iterable
    {
        yield 'CreditCard' => [SensitiveDataType::CreditCard, 'credit_card'];
        yield 'Ssn' => [SensitiveDataType::Ssn, 'ssn'];
        yield 'ApiKey' => [SensitiveDataType::ApiKey, 'api_key'];
        yield 'Email' => [SensitiveDataType::Email, 'email'];
        yield 'IpAddress' => [SensitiveDataType::IpAddress, 'ip_address'];
        yield 'Ephi' => [SensitiveDataType::Ephi, 'ephi'];
        yield 'Custom' => [SensitiveDataType::Custom, 'custom'];
    }

    // ── SensitivePattern ────────────────────────────────────────────

    #[Test]
    public function sensitivePatternStoresProperties(): void
    {
        $pattern = new SensitivePattern(
            type: SensitiveDataType::CreditCard,
            regex: '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',
        );

        self::assertSame(SensitiveDataType::CreditCard, $pattern->type);
        self::assertSame('/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/', $pattern->regex);
        self::assertNull($pattern->validator);
    }

    #[Test]
    public function sensitivePatternWithValidator(): void
    {
        $validator = static fn(string $v): bool => strlen($v) > 10;

        $pattern = new SensitivePattern(
            type: SensitiveDataType::Ssn,
            regex: '/\b\d{3}-\d{2}-\d{4}\b/',
            validator: $validator,
        );

        self::assertSame(SensitiveDataType::Ssn, $pattern->type);
        self::assertNotNull($pattern->validator);
        self::assertTrue(($pattern->validator)('123-45-67890'));
        self::assertFalse(($pattern->validator)('short'));
    }

    #[Test]
    public function sensitivePatternValidatorDefaultsToNull(): void
    {
        $pattern = new SensitivePattern(
            type: SensitiveDataType::Email,
            regex: '/[a-z]+@[a-z]+\.com/',
        );

        self::assertNull($pattern->validator);
    }
}
