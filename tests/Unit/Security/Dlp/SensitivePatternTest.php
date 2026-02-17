<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePattern;

#[CoversClass(SensitivePattern::class)]
final class SensitivePatternTest extends TestCase
{
    #[Test]
    public function constructorSetsTypeAndRegex(): void
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
    public function constructorWithValidator(): void
    {
        $luhnCheck = static fn(string $value): bool => true;

        $pattern = new SensitivePattern(
            type: SensitiveDataType::CreditCard,
            regex: '/\b\d{16}\b/',
            validator: $luhnCheck,
        );

        self::assertSame($luhnCheck, $pattern->validator);
    }

    #[Test]
    public function validatorDefaultsToNull(): void
    {
        $pattern = new SensitivePattern(
            type: SensitiveDataType::Ssn,
            regex: '/\b\d{3}-\d{2}-\d{4}\b/',
        );

        self::assertNull($pattern->validator);
    }

    #[Test]
    public function eachDataTypeCanBeUsedWithPattern(): void
    {
        foreach (SensitiveDataType::cases() as $type) {
            $pattern = new SensitivePattern(
                type: $type,
                regex: '/test/',
            );

            self::assertSame($type, $pattern->type);
        }
    }

    #[Test]
    public function customTypeWithCustomValidator(): void
    {
        $validator = static fn(string $value): bool => str_starts_with($value, 'CUSTOM-');

        $pattern = new SensitivePattern(
            type: SensitiveDataType::Custom,
            regex: '/\bCUSTOM-[A-Z0-9]+\b/',
            validator: $validator,
        );

        self::assertSame(SensitiveDataType::Custom, $pattern->type);
        self::assertNotNull($pattern->validator);
        self::assertTrue(($pattern->validator)('CUSTOM-ABC123'));
        self::assertFalse(($pattern->validator)('OTHER-ABC123'));
    }
}
