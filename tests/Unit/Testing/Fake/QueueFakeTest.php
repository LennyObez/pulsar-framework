<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Fake;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Testing\Fake\QueueFake;

#[CoversClass(QueueFake::class)]
final class QueueFakeTest extends TestCase
{
    private QueueFake $fake;

    protected function setUp(): void
    {
        $this->fake = new QueueFake();
    }

    #[Test]
    public function push_records_job(): void
    {
        $id = $this->fake->push('default', 'App\Jobs\SendEmail', '{}');

        self::assertNotEmpty($id);
        self::assertCount(1, $this->fake->pushed());
    }

    #[Test]
    public function push_returns_unique_ids(): void
    {
        $id1 = $this->fake->push('default', 'App\Jobs\A', '{}');
        $id2 = $this->fake->push('default', 'App\Jobs\B', '{}');

        self::assertNotSame($id1, $id2);
    }

    #[Test]
    public function pop_returns_pending_job(): void
    {
        $this->fake->push('default', 'App\Jobs\SendEmail', '{"to":"a@b.com"}');

        $job = $this->fake->pop('default');

        self::assertNotNull($job);
        self::assertSame('App\Jobs\SendEmail', $job->jobClass);
        self::assertSame(JobRecordStatus::Processing, $job->status);
    }

    #[Test]
    public function pop_returns_null_when_empty(): void
    {
        self::assertNull($this->fake->pop('default'));
    }

    #[Test]
    public function acknowledge_marks_job_completed(): void
    {
        $id = $this->fake->push('default', 'App\Jobs\A', '{}');

        $this->fake->acknowledge($id);

        $completed = $this->fake->findByStatus(JobRecordStatus::Completed);
        self::assertCount(1, $completed);
        self::assertSame($id, $completed[0]->id);
    }

    #[Test]
    public function reject_marks_job_failed(): void
    {
        $id = $this->fake->push('default', 'App\Jobs\A', '{}');

        $this->fake->reject($id, 'Test failure');

        $failed = $this->fake->findByStatus(JobRecordStatus::Failed);
        self::assertCount(1, $failed);
    }

    #[Test]
    public function size_counts_pending_jobs_on_queue(): void
    {
        $this->fake->push('default', 'App\Jobs\A', '{}');
        $this->fake->push('default', 'App\Jobs\B', '{}');
        $this->fake->push('other', 'App\Jobs\C', '{}');

        self::assertSame(2, $this->fake->size('default'));
        self::assertSame(1, $this->fake->size('other'));
    }

    #[Test]
    public function purge_removes_all_jobs_on_queue(): void
    {
        $this->fake->push('default', 'App\Jobs\A', '{}');
        $this->fake->push('default', 'App\Jobs\B', '{}');
        $this->fake->push('other', 'App\Jobs\C', '{}');

        $purged = $this->fake->purge('default');

        self::assertSame(2, $purged);
        self::assertSame(0, $this->fake->size('default'));
        self::assertSame(1, $this->fake->size('other'));
    }

    #[Test]
    public function assert_pushed_passes_when_job_exists(): void
    {
        $this->fake->push('default', 'App\Jobs\SendEmail', '{}');

        $this->fake->assertPushed('App\Jobs\SendEmail');
    }

    #[Test]
    public function assert_pushed_fails_when_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Expected job [App\Jobs\SendEmail] to be pushed');

        $this->fake->assertPushed('App\Jobs\SendEmail');
    }

    #[Test]
    public function assert_pushed_with_exact_count(): void
    {
        $this->fake->push('default', 'App\Jobs\A', '{}');
        $this->fake->push('default', 'App\Jobs\A', '{}');

        $this->fake->assertPushed('App\Jobs\A', 2);
    }

    #[Test]
    public function assert_pushed_on_specific_queue(): void
    {
        $this->fake->push('notifications', 'App\Jobs\Notify', '{}');

        $this->fake->assertPushedOn('notifications', 'App\Jobs\Notify');
    }

    #[Test]
    public function assert_pushed_on_fails_when_wrong_queue(): void
    {
        $this->fake->push('default', 'App\Jobs\Notify', '{}');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('pushed on queue [notifications]');

        $this->fake->assertPushedOn('notifications', 'App\Jobs\Notify');
    }

    #[Test]
    public function assert_not_pushed_passes_when_absent(): void
    {
        $this->fake->assertNotPushed('App\Jobs\Missing');
    }

    #[Test]
    public function assert_nothing_pushed_passes_when_empty(): void
    {
        $this->fake->assertNothingPushed();
    }

    #[Test]
    public function assert_pushed_with_callback(): void
    {
        $this->fake->push('default', 'App\Jobs\SendEmail', '{"to":"test@example.com"}');

        $this->fake->assertPushedWith(
            'App\Jobs\SendEmail',
            static fn(JobRecord $job): bool => $job->payload === '{"to":"test@example.com"}',
        );
    }

    #[Test]
    public function jobs_of_type_returns_filtered_list(): void
    {
        $this->fake->push('default', 'App\Jobs\A', '{}');
        $this->fake->push('default', 'App\Jobs\B', '{}');
        $this->fake->push('default', 'App\Jobs\A', '{}');

        self::assertCount(2, $this->fake->jobsOfType('App\Jobs\A'));
        self::assertCount(1, $this->fake->jobsOfType('App\Jobs\B'));
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $this->fake->push('default', 'App\Jobs\A', '{}');

        $this->fake->reset();

        self::assertCount(0, $this->fake->pushed());
        self::assertSame(0, $this->fake->size('default'));
    }
}
