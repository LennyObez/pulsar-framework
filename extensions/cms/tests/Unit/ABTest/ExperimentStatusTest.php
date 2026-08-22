<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\ABTest;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;

#[CoversNothing]
final class ExperimentStatusTest extends TestCase
{
    #[Test]
    #[DataProvider('statusProvider')]
    public function fromValueResolves(string $value, ExperimentStatus $expected): void
    {
        self::assertSame($expected, ExperimentStatus::from($value));
    }

    /**
     * @return array<string, array{string, ExperimentStatus}>
     */
    public static function statusProvider(): array
    {
        return [
            'draft' => ['draft', ExperimentStatus::Draft],
            'running' => ['running', ExperimentStatus::Running],
            'completed' => ['completed', ExperimentStatus::Completed],
            'cancelled' => ['cancelled', ExperimentStatus::Cancelled],
        ];
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(ExperimentStatus::tryFrom('paused'));
    }
}
