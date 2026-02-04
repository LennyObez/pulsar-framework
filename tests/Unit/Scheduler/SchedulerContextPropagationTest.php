<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Scheduler;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;

use function strlen;

#[CoversClass(Scheduler::class)]
final class SchedulerContextPropagationTest extends TestCase
{
    #[Test]
    public function it_creates_fresh_context_per_scheduled_job(): void
    {
        $holder = new RequestContextHolder();
        $randomizer = new Randomizer(new Secure());
        $registry = new JobRegistry();

        /** @var list<JobContext> $capturedContexts */
        $capturedContexts = [];

        $registry->register(new CallbackJob(
            name: 'context-job-a',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx) use (&$capturedContexts): string {
                $capturedContexts[] = $ctx;

                return 'a';
            },
        ));

        $registry->register(new CallbackJob(
            name: 'context-job-b',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx) use (&$capturedContexts): string {
                $capturedContexts[] = $ctx;

                return 'b';
            },
        ));

        $scheduler = new Scheduler($registry, null, null, $holder, $randomizer);
        $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        self::assertCount(2, $capturedContexts);

        // Both jobs should have RequestContext
        self::assertNotNull($capturedContexts[0]->requestContext);
        self::assertNotNull($capturedContexts[1]->requestContext);

        // Each job should get a different CorrelationId (fresh per job)
        self::assertNotSame(
            $capturedContexts[0]->requestContext->correlationId->value,
            $capturedContexts[1]->requestContext->correlationId->value,
        );
    }

    #[Test]
    public function it_clears_holder_after_job_execution(): void
    {
        $holder = new RequestContextHolder();
        $randomizer = new Randomizer(new Secure());
        $registry = new JobRegistry();

        $registry->register(new CallbackJob(
            name: 'cleanup-job',
            schedule: Schedule::everyMinute(),
            callback: static fn(JobContext $ctx): string => 'done',
        ));

        $scheduler = new Scheduler($registry, null, null, $holder, $randomizer);
        $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        // Holder should be cleared after execution
        self::assertFalse($holder->isAvailable());
    }

    #[Test]
    public function it_sets_context_in_holder_during_execution(): void
    {
        $holder = new RequestContextHolder();
        $randomizer = new Randomizer(new Secure());
        $registry = new JobRegistry();

        $holderWasAvailable = false;

        $registry->register(new CallbackJob(
            name: 'holder-check-job',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx) use ($holder, &$holderWasAvailable): string {
                $holderWasAvailable = $holder->isAvailable();

                return 'checked';
            },
        ));

        $scheduler = new Scheduler($registry, null, null, $holder, $randomizer);
        $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        self::assertTrue($holderWasAvailable);
    }

    #[Test]
    public function it_skips_context_without_holder(): void
    {
        $registry = new JobRegistry();
        $capturedContext = null;

        $registry->register(new CallbackJob(
            name: 'no-holder-job',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx) use (&$capturedContext): string {
                $capturedContext = $ctx;

                return 'done';
            },
        ));

        // No holder, no randomizer — should work without context
        $scheduler = new Scheduler($registry);
        $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        /** @var JobContext $capturedContext */
        self::assertNull($capturedContext->requestContext);
    }

    #[Test]
    public function it_clears_holder_even_when_job_throws(): void
    {
        $holder = new RequestContextHolder();
        $randomizer = new Randomizer(new Secure());
        $registry = new JobRegistry();

        $registry->register(new CallbackJob(
            name: 'failing-ctx-job',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx): never {
                throw new RuntimeException('Boom');
            },
        ));

        $scheduler = new Scheduler($registry, null, null, $holder, $randomizer);
        $scheduler->tick(new DateTimeImmutable('2026-01-05 09:30:00'));

        // Holder should be cleared even after failure
        self::assertFalse($holder->isAvailable());
    }

    #[Test]
    public function runJob_provides_context_in_job_context(): void
    {
        $holder = new RequestContextHolder();
        $randomizer = new Randomizer(new Secure());
        $registry = new JobRegistry();

        $capturedContext = null;

        $job = new CallbackJob(
            name: 'direct-run-job',
            schedule: Schedule::everyMinute(),
            callback: static function (JobContext $ctx) use (&$capturedContext): string {
                $capturedContext = $ctx;

                return 'ran';
            },
        );

        $scheduler = new Scheduler($registry, null, null, $holder, $randomizer);
        $scheduler->runJob($job);

        /** @var JobContext $capturedContext */
        self::assertNotNull($capturedContext->requestContext);
        self::assertSame(32, strlen($capturedContext->requestContext->correlationId->value));
        self::assertSame(32, strlen($capturedContext->requestContext->causationId->value));
    }
}
