<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\VerificationStatus;

#[CoversNothing]
final class VerificationStatusTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, VerificationStatus::cases());
    }

    #[Test]
    #[DataProvider('verificationStatusProvider')]
    public function backedValues(VerificationStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{VerificationStatus, string}>
     */
    public static function verificationStatusProvider(): iterable
    {
        yield 'Ok' => [VerificationStatus::Ok, 'ok'];
        yield 'Modified' => [VerificationStatus::Modified, 'modified'];
        yield 'Missing' => [VerificationStatus::Missing, 'missing'];
        yield 'Extra' => [VerificationStatus::Extra, 'extra'];
    }

    #[Test]
    public function fromBackedValue(): void
    {
        self::assertSame(VerificationStatus::Modified, VerificationStatus::from('modified'));
        self::assertSame(VerificationStatus::Ok, VerificationStatus::from('ok'));
    }
}
