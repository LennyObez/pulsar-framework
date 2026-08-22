<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversNothing]
final class AuditOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{AuditOutcome, string}>
     */
    public static function outcomeProvider(): iterable
    {
        yield 'Success' => [AuditOutcome::Success, 'success'];
        yield 'Failure' => [AuditOutcome::Failure, 'failure'];
        yield 'Denied' => [AuditOutcome::Denied, 'denied'];
        yield 'Error' => [AuditOutcome::Error, 'error'];
    }

    #[Test]
    #[DataProvider('outcomeProvider')]
    public function backingValueMatchesExpected(AuditOutcome $outcome, string $expected): void
    {
        self::assertSame($expected, $outcome->value);
    }

    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, AuditOutcome::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(AuditOutcome::Success, AuditOutcome::from('success'));
        self::assertSame(AuditOutcome::Denied, AuditOutcome::from('denied'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(AuditOutcome::tryFrom('unknown'));
    }
}
