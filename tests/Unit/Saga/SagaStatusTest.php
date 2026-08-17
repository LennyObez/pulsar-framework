<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\SagaStatus;

#[CoversNothing]
final class SagaStatusTest extends TestCase
{
    #[Test]
    public function test_running_value(): void
    {
        self::assertSame('running', SagaStatus::Running->value);
    }

    #[Test]
    public function test_compensating_value(): void
    {
        self::assertSame('compensating', SagaStatus::Compensating->value);
    }

    #[Test]
    public function test_completed_value(): void
    {
        self::assertSame('completed', SagaStatus::Completed->value);
    }

    #[Test]
    public function test_failed_value(): void
    {
        self::assertSame('failed', SagaStatus::Failed->value);
    }

    #[Test]
    public function test_from_valid_strings(): void
    {
        self::assertSame(SagaStatus::Running, SagaStatus::from('running'));
        self::assertSame(SagaStatus::Compensating, SagaStatus::from('compensating'));
        self::assertSame(SagaStatus::Completed, SagaStatus::from('completed'));
        self::assertSame(SagaStatus::Failed, SagaStatus::from('failed'));
    }

    #[Test]
    public function test_tryFrom_invalid_string_returns_null(): void
    {
        /** @var string $invalid */
        $invalid = 'cancelled';

        self::assertNull(SagaStatus::tryFrom($invalid));
    }
}
