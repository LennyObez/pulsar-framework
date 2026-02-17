<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\ScheduleBuilder;
use Pulsar\Scheduler\ScheduledJob;

final class ScheduleBuilderTest extends TestCase
{
    #[Test]
    public function buildProducesScheduledJob(): void
    {
        $job = ScheduleBuilder::job('cleanup', static fn() => 'done')
            ->daily()
            ->description('Daily cleanup')
            ->build();

        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertSame('cleanup', $job->getName());
        self::assertSame('Daily cleanup', $job->getDescription());
    }

    /**
     * @param list<mixed> $args
     */
    #[Test]
    #[DataProvider('frequencyProvider')]
    public function frequencyMethodsSetsCorrectCron(string $method, string $expectedExpression, array $args = []): void
    {
        $builder = ScheduleBuilder::job('test', static fn() => null);

        /** @var callable $call */
        $call = [$builder, $method];
        $call(...$args);

        $job = $builder->build();

        self::assertSame($expectedExpression, $job->getSchedule()->expression);
    }

    /**
     * @return iterable<string, array{string, string, list<mixed>}>
     */
    public static function frequencyProvider(): iterable
    {
        yield 'everyMinute' => ['everyMinute', '* * * * *', []];
        yield 'everyFiveMinutes' => ['everyFiveMinutes', '*/5 * * * *', []];
        yield 'everyTenMinutes' => ['everyTenMinutes', '*/10 * * * *', []];
        yield 'everyFifteenMinutes' => ['everyFifteenMinutes', '*/15 * * * *', []];
        yield 'everyThirtyMinutes' => ['everyThirtyMinutes', '*/30 * * * *', []];
        yield 'hourly' => ['hourly', '0 * * * *', []];
        yield 'hourlyAt' => ['hourlyAt', '15 * * * *', [15]];
        yield 'daily' => ['daily', '0 0 * * *', []];
        yield 'dailyAt' => ['dailyAt', '0 3 * * *', ['03:00']];
        yield 'twiceDaily' => ['twiceDaily', '0 6,18 * * *', [6, 18]];
        yield 'weeklyOn' => ['weeklyOn', '0 8 * * 1', [1, '08:00']];
        yield 'monthlyOn' => ['monthlyOn', '0 0 15 * *', [15]];
        yield 'cron' => ['cron', '5 4 * * 0', ['5 4 * * 0']];
    }

    #[Test]
    public function timezoneIsPreserved(): void
    {
        $job = ScheduleBuilder::job('test', static fn() => null)
            ->daily()
            ->timezone('America/New_York')
            ->build();

        self::assertSame('America/New_York', $job->getSchedule()->timezone);
    }

    #[Test]
    public function withoutOverlappingEnablesOverlapPrevention(): void
    {
        $job = ScheduleBuilder::job('test', static fn() => null)
            ->daily()
            ->withoutOverlapping(60)
            ->build();

        self::assertTrue($job->preventsOverlap());
    }

    #[Test]
    public function evenInMaintenanceModeFlag(): void
    {
        $job = ScheduleBuilder::job('test', static fn() => null)
            ->daily()
            ->evenInMaintenanceMode()
            ->build();

        self::assertTrue($job->runsInMaintenanceMode());
    }

    #[Test]
    public function emailOutputToIsPreserved(): void
    {
        $job = ScheduleBuilder::job('test', static fn() => null)
            ->daily()
            ->emailOutputTo('admin@example.com')
            ->build();

        self::assertSame('admin@example.com', $job->emailOutputTo());
    }

    #[Test]
    public function chainingReturnsBuilder(): void
    {
        $builder = ScheduleBuilder::job('test', static fn() => null)
            ->daily()
            ->timezone('UTC')
            ->description('desc')
            ->withoutOverlapping()
            ->evenInMaintenanceMode()
            ->appendOutputTo('/tmp/out.log')
            ->emailOutputTo('admin@test.com');

        // Chaining should return the builder, not break the fluent API
        self::assertInstanceOf(ScheduleBuilder::class, $builder);
    }
}
