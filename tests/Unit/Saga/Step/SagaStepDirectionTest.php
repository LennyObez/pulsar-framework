<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Step;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Storage\SagaStepDirection;

#[CoversClass(SagaStepDirection::class)]
final class SagaStepDirectionTest extends TestCase
{
    #[Test]
    public function test_forward_value(): void
    {
        self::assertSame('forward', SagaStepDirection::Forward->value);
    }

    #[Test]
    public function test_compensating_value(): void
    {
        self::assertSame('compensating', SagaStepDirection::Compensating->value);
    }

    #[Test]
    public function test_from_valid_strings(): void
    {
        self::assertSame(SagaStepDirection::Forward, SagaStepDirection::from('forward'));
        self::assertSame(SagaStepDirection::Compensating, SagaStepDirection::from('compensating'));
    }
}
