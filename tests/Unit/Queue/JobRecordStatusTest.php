<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\JobRecordStatus;

#[CoversNothing]
final class JobRecordStatusTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('pending', JobRecordStatus::Pending->value);
        self::assertSame('processing', JobRecordStatus::Processing->value);
        self::assertSame('completed', JobRecordStatus::Completed->value);
        self::assertSame('failed', JobRecordStatus::Failed->value);
        self::assertSame('dead_lettered', JobRecordStatus::DeadLettered->value);
    }
}
