<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\WorkerStatus;

#[CoversNothing]
final class WorkerStatusTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('running', WorkerStatus::Running->value);
        self::assertSame('paused', WorkerStatus::Paused->value);
        self::assertSame('stopping', WorkerStatus::Stopping->value);
        self::assertSame('stopped', WorkerStatus::Stopped->value);
    }
}
