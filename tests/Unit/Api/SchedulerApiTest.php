<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Scheduler\CronFields;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobEvent;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\SchedulerTickResult;

#[CoversClass(Api::class)]
final class SchedulerApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function jobInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(JobInterface::class);
    }

    #[Test]
    public function scheduleIsPublicApi(): void
    {
        self::assertHasApiAttribute(Schedule::class);
        self::assertClassIsReadonly(Schedule::class);
    }

    #[Test]
    public function scheduleHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(Schedule::class, 'everyMinute');
        self::assertStaticFactoryExists(Schedule::class, 'hourly');
        self::assertStaticFactoryExists(Schedule::class, 'daily');
        self::assertStaticFactoryExists(Schedule::class, 'weekly');
        self::assertStaticFactoryExists(Schedule::class, 'monthly');
        self::assertStaticFactoryExists(Schedule::class, 'cron');
    }

    #[Test]
    public function cronFieldsIsPublicApi(): void
    {
        self::assertHasApiAttribute(CronFields::class);
        self::assertClassIsReadonly(CronFields::class);
    }

    #[Test]
    public function jobContextIsPublicApi(): void
    {
        self::assertHasApiAttribute(JobContext::class);
        self::assertClassIsReadonly(JobContext::class);
    }

    #[Test]
    public function jobResultIsPublicApi(): void
    {
        self::assertHasApiAttribute(JobResult::class);
        self::assertClassIsReadonly(JobResult::class);
    }

    #[Test]
    public function schedulerTickResultIsPublicApi(): void
    {
        self::assertHasApiAttribute(SchedulerTickResult::class);
        self::assertClassIsReadonly(SchedulerTickResult::class);
    }

    #[Test]
    public function schedulerEnumsArePublicApi(): void
    {
        self::assertHasApiAttribute(JobEvent::class);
        self::assertHasApiAttribute(JobStatus::class);
    }

    #[Test]
    public function schedulerExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(SchedulerException::class);
    }

    #[Test]
    public function schedulerExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(SchedulerException::class, 'jobNotFound');
        self::assertStaticFactoryExists(SchedulerException::class, 'executionTimeout');
        self::assertStaticFactoryExists(SchedulerException::class, 'invalidCronExpression');
        self::assertStaticFactoryExists(SchedulerException::class, 'duplicateJob');
    }
}
