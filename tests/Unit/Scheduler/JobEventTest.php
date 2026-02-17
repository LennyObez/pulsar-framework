<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\JobEvent;

#[CoversClass(JobEvent::class)]
final class JobEventTest extends TestCase
{
    #[Test]
    public function all_cases_exist(): void
    {
        self::assertCount(4, JobEvent::cases());
    }

    #[Test]
    #[DataProvider('caseValueProvider')]
    public function backed_values(string $expected, JobEvent $case): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{string, JobEvent}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'BeforeExecute' => ['before_execute', JobEvent::BeforeExecute];
        yield 'AfterExecute' => ['after_execute', JobEvent::AfterExecute];
        yield 'Failed' => ['failed', JobEvent::Failed];
        yield 'Skipped' => ['skipped', JobEvent::Skipped];
    }

    #[Test]
    public function try_from_valid(): void
    {
        self::assertSame(JobEvent::Failed, JobEvent::tryFrom('failed'));
    }

    #[Test]
    public function try_from_invalid_returns_null(): void
    {
        self::assertNull(JobEvent::tryFrom('nonexistent'));
    }
}
