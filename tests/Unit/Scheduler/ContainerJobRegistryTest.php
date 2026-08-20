<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Scheduler\ContainerJobRegistry;
use Pulsar\Scheduler\ContainerResolvedJob;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use RuntimeException;

#[CoversClass(ContainerJobRegistry::class)]
#[CoversClass(ContainerResolvedJob::class)]
final class ContainerJobRegistryTest extends TestCase
{
    #[Test]
    public function registeringAClassNamePutsItInTheUnderlyingRegistry(): void
    {
        $registry = new JobRegistry();
        $container = new Container();

        new ContainerJobRegistry($registry, $container)
            ->register(SpyInvokableJob::class, Schedule::everyMinute());

        self::assertTrue($registry->has(SpyInvokableJob::class));
        self::assertSame(Schedule::everyMinute()->expression, $registry->get(SpyInvokableJob::class)->getSchedule()->expression);
    }

    #[Test]
    public function registrationDoesNotBuildTheJob(): void
    {
        $registry = new JobRegistry();
        $container = new Container();
        $container->bind(SpyInvokableJob::class, static function (): never {
            throw new RuntimeException('the job was built during registration');
        });

        new ContainerJobRegistry($registry, $container)
            ->register(SpyInvokableJob::class, Schedule::everyMinute());

        // Registration runs on every request; the job only ever runs from
        // schedule:run, so its dependency graph must stay unbuilt until then.
        self::assertTrue($registry->has(SpyInvokableJob::class));
    }

    #[Test]
    public function executingDelegatesToAJobInterfaceImplementation(): void
    {
        $registry = new JobRegistry();
        $container = new Container();
        $container->instance(SpyJobInterfaceJob::class, new SpyJobInterfaceJob());

        new ContainerJobRegistry($registry, $container)
            ->register(SpyJobInterfaceJob::class, Schedule::everyMinute());

        $result = $registry->get(SpyJobInterfaceJob::class)->execute($this->context());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertSame('delegated', $result->output);
    }

    #[Test]
    public function executingCallsAPlainInvokable(): void
    {
        $registry = new JobRegistry();
        $container = new Container();
        $job = new SpyInvokableJob();
        $container->instance(SpyInvokableJob::class, $job);

        new ContainerJobRegistry($registry, $container)
            ->register(SpyInvokableJob::class, Schedule::everyMinute());

        $result = $registry->get(SpyInvokableJob::class)->execute($this->context());

        self::assertTrue($job->ran, 'the invokable must actually be called');
        self::assertSame(JobStatus::Success, $result->status);
    }

    #[Test]
    public function aScheduledClassThatIsNeitherAJobNorInvokableFails(): void
    {
        $registry = new JobRegistry();
        $container = new Container();
        $container->instance(NotAJob::class, new NotAJob());

        new ContainerJobRegistry($registry, $container)
            ->register(NotAJob::class, Schedule::everyMinute());

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageMatches('/must implement JobInterface or be invokable/');
        $registry->get(NotAJob::class)->execute($this->context());
    }

    #[Test]
    public function theRegisteredScheduleWinsOverTheOneTheClassDeclares(): void
    {
        $registry = new JobRegistry();
        $container = new Container();
        $container->instance(SpyJobInterfaceJob::class, new SpyJobInterfaceJob());

        new ContainerJobRegistry($registry, $container)
            ->register(SpyJobInterfaceJob::class, Schedule::daily());

        self::assertSame(
            Schedule::daily()->expression,
            $registry->get(SpyJobInterfaceJob::class)->getSchedule()->expression,
            'the schedule stated at the call site is the one the caller asked for',
        );
        self::assertNotSame(
            new SpyJobInterfaceJob()->getSchedule()->expression,
            $registry->get(SpyJobInterfaceJob::class)->getSchedule()->expression,
        );
    }

    private function context(): JobContext
    {
        $now = new DateTimeImmutable('2026-08-19 09:00:00');

        return new JobContext(scheduledAt: $now, startedAt: $now);
    }
}

final class SpyInvokableJob
{
    public bool $ran = false;

    public function __invoke(): void
    {
        $this->ran = true;
    }
}

final class SpyJobInterfaceJob implements JobInterface
{
    public function getName(): string
    {
        return 'spy.job';
    }

    public function getSchedule(): Schedule
    {
        return Schedule::everyMinute();
    }

    public function getDescription(): string
    {
        return 'spy';
    }

    public function execute(JobContext $context): JobResult
    {
        return JobResult::success($this->getName(), $context->startedAt, 'delegated');
    }
}

final class NotAJob {}
