<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Step;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Step\StepResult;
use RuntimeException;

#[CoversClass(StepResult::class)]
final class StepResultTest extends TestCase
{
    #[Test]
    public function test_success_without_output(): void
    {
        $result = StepResult::success('charge_payment');

        self::assertSame('charge_payment', $result->stepName);
        self::assertTrue($result->success);
        self::assertSame([], $result->output);
        self::assertNull($result->error);
    }

    #[Test]
    public function test_success_with_output(): void
    {
        $output = ['transactionId' => 'txn_123', 'amount' => 5000];
        $result = StepResult::success('charge_payment', $output);

        self::assertSame('charge_payment', $result->stepName);
        self::assertTrue($result->success);
        self::assertSame($output, $result->output);
        self::assertNull($result->error);
    }

    #[Test]
    public function test_failure(): void
    {
        $error = new RuntimeException('Payment declined');
        $result = StepResult::failure('charge_payment', $error);

        self::assertSame('charge_payment', $result->stepName);
        self::assertFalse($result->success);
        self::assertSame([], $result->output);
        self::assertSame($error, $result->error);
    }
}
