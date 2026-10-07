<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\CloudSwitching;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\CloudSwitching\SwitchingStatus;

#[CoversNothing]
final class SwitchingStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{SwitchingStatus, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'initiated' => [SwitchingStatus::Initiated, 'initiated'];
        yield 'exporting' => [SwitchingStatus::Exporting, 'exporting'];
        yield 'transferring' => [SwitchingStatus::Transferring, 'transferring'];
        yield 'completed' => [SwitchingStatus::Completed, 'completed'];
        yield 'cancelled' => [SwitchingStatus::Cancelled, 'cancelled'];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function backingValueMatchesExpected(SwitchingStatus $status, string $value): void
    {
        self::assertSame($value, $status->value);
    }

    #[Test]
    public function allCasesAreEnumerated(): void
    {
        self::assertCount(5, SwitchingStatus::cases());
    }
}
