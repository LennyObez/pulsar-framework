<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Exception\ConcurrentTransitionException;
use Pulsar\Workflow\Exception\WorkflowException;

#[CoversClass(ConcurrentTransitionException::class)]
final class ConcurrentTransitionExceptionTest extends TestCase
{
    #[Test]
    public function test_constructor_stores_instance_id_and_expected_version(): void
    {
        $exception = new ConcurrentTransitionException(
            instanceId: 'inst-42',
            expectedVersion: 5,
        );

        self::assertSame('inst-42', $exception->instanceId);
        self::assertSame(5, $exception->expectedVersion);
    }

    #[Test]
    public function test_message_contains_instance_id_and_version(): void
    {
        $exception = new ConcurrentTransitionException('inst-42', 5);

        self::assertStringContainsString('inst-42', $exception->getMessage());
        self::assertStringContainsString('5', $exception->getMessage());
    }

    #[Test]
    public function test_extends_workflow_exception(): void
    {
        $exception = new ConcurrentTransitionException('inst-1', 1);

        self::assertInstanceOf(WorkflowException::class, $exception);
    }

    #[Test]
    public function test_for_instance_factory_creates_exception(): void
    {
        $exception = ConcurrentTransitionException::forInstance('inst-99', 10);

        self::assertSame('inst-99', $exception->instanceId);
        self::assertSame(10, $exception->expectedVersion);
        self::assertStringContainsString('inst-99', $exception->getMessage());
    }
}
