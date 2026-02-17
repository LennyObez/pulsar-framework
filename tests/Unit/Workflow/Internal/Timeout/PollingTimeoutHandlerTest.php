<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Internal\Timeout;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Internal\Timeout\PollingTimeoutHandler;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;

final class PollingTimeoutHandlerTest extends TestCase
{
    #[Test]
    public function schedule_timeout_calls_storage_with_deadline(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);

        $storage->expects(self::once())
            ->method('updateTimeout')
            ->with(
                'instance-1',
                self::callback(static function (DateTimeImmutable $deadline): bool {
                    // The deadline should be roughly "now + 1 hour"
                    $diff = $deadline->getTimestamp() - time();

                    return $diff >= 3590 && $diff <= 3610;
                }),
            );

        $handler = new PollingTimeoutHandler($storage);
        $handler->scheduleTimeout('instance-1', new DateInterval('PT1H'));
    }

    #[Test]
    public function cancel_timeout_calls_storage_with_null(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);

        $storage->expects(self::once())
            ->method('updateTimeout')
            ->with('instance-1', null);

        $handler = new PollingTimeoutHandler($storage);
        $handler->cancelTimeout('instance-1');
    }
}
