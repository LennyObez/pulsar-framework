<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\BcBreak;
use Pulsar\Api\BcBreakSeverity;
use Pulsar\Api\BcBreakType;

#[CoversClass(BcBreak::class)]
final class BcBreakTest extends TestCase
{
    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $break = new BcBreak(
            type: BcBreakType::MethodRemoved,
            symbol: 'App\\Service::doStuff',
            message: 'Method doStuff was removed',
            severity: BcBreakSeverity::Error,
            stability: 'stable',
        );

        self::assertSame(BcBreakType::MethodRemoved, $break->type);
        self::assertSame('App\\Service::doStuff', $break->symbol);
        self::assertSame('Method doStuff was removed', $break->message);
        self::assertSame(BcBreakSeverity::Error, $break->severity);
        self::assertSame('stable', $break->stability);
    }

    #[Test]
    public function warningBreakOnExperimentalApi(): void
    {
        $break = new BcBreak(
            type: BcBreakType::SignatureChanged,
            symbol: 'App\\Experimental::tryMethod',
            message: 'Parameter type changed',
            severity: BcBreakSeverity::Warning,
            stability: 'experimental',
        );

        self::assertSame(BcBreakSeverity::Warning, $break->severity);
        self::assertSame('experimental', $break->stability);
    }

    // ── BcBreakType enum ────────────────────────────────────────────

    #[Test]
    #[DataProvider('breakTypeProvider')]
    public function breakTypeBackedValues(BcBreakType $type, string $expectedValue): void
    {
        self::assertSame($expectedValue, $type->value);
    }

    /**
     * @return iterable<string, array{BcBreakType, string}>
     */
    public static function breakTypeProvider(): iterable
    {
        yield 'ClassRemoved' => [BcBreakType::ClassRemoved, 'class_removed'];
        yield 'MethodRemoved' => [BcBreakType::MethodRemoved, 'method_removed'];
        yield 'SignatureChanged' => [BcBreakType::SignatureChanged, 'signature_changed'];
        yield 'ReturnTypeNarrowed' => [BcBreakType::ReturnTypeNarrowed, 'return_type_narrowed'];
        yield 'ConstantRemoved' => [BcBreakType::ConstantRemoved, 'constant_removed'];
    }

    #[Test]
    public function breakTypeFromBackedValue(): void
    {
        self::assertSame(BcBreakType::ClassRemoved, BcBreakType::from('class_removed'));
        self::assertSame(BcBreakType::ConstantRemoved, BcBreakType::from('constant_removed'));
    }

    #[Test]
    public function breakTypeHasFiveCases(): void
    {
        self::assertCount(5, BcBreakType::cases());
    }

    // ── BcBreakSeverity enum ────────────────────────────────────────

    #[Test]
    public function severityErrorValue(): void
    {
        self::assertSame('error', BcBreakSeverity::Error->value);
    }

    #[Test]
    public function severityWarningValue(): void
    {
        self::assertSame('warning', BcBreakSeverity::Warning->value);
    }

    #[Test]
    public function severityFromBackedValue(): void
    {
        self::assertSame(BcBreakSeverity::Error, BcBreakSeverity::from('error'));
        self::assertSame(BcBreakSeverity::Warning, BcBreakSeverity::from('warning'));
    }

    #[Test]
    public function severityHasTwoCases(): void
    {
        self::assertCount(2, BcBreakSeverity::cases());
    }
}
