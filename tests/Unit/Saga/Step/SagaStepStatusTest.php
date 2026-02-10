<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Step;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Storage\SagaStepStatus;

#[CoversClass(SagaStepStatus::class)]
final class SagaStepStatusTest extends TestCase
{
    #[Test]
    public function test_case_values(): void
    {
        self::assertSame('pending', SagaStepStatus::Pending->value);
        self::assertSame('running', SagaStepStatus::Running->value);
        self::assertSame('completed', SagaStepStatus::Completed->value);
        self::assertSame('failed', SagaStepStatus::Failed->value);
        self::assertSame('skipped', SagaStepStatus::Skipped->value);
    }

    #[Test]
    public function test_from_valid_strings(): void
    {
        self::assertSame(SagaStepStatus::Pending, SagaStepStatus::from('pending'));
        self::assertSame(SagaStepStatus::Running, SagaStepStatus::from('running'));
        self::assertSame(SagaStepStatus::Completed, SagaStepStatus::from('completed'));
        self::assertSame(SagaStepStatus::Failed, SagaStepStatus::from('failed'));
        self::assertSame(SagaStepStatus::Skipped, SagaStepStatus::from('skipped'));
    }
}
