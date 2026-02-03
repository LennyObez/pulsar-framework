<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\JobRecordStatus;

use function strlen;

#[CoversClass(InMemoryDriver::class)]
final class InMemoryDriverTest extends TestCase
{
    private InMemoryDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new InMemoryDriver();
    }

    #[Test]
    public function it_pushes_a_job_and_returns_unique_id(): void
    {
        $id = $this->driver->push('default', 'App\\Jobs\\Noop', '{}');

        self::assertNotEmpty($id);
        self::assertSame(32, strlen($id)); // bin2hex(16 bytes) = 32 hex chars
    }

    #[Test]
    public function it_pushes_multiple_jobs_with_unique_ids(): void
    {
        $id1 = $this->driver->push('default', 'App\\Jobs\\Noop', '{}');
        $id2 = $this->driver->push('default', 'App\\Jobs\\Noop', '{}');

        self::assertNotSame($id1, $id2);
    }

    #[Test]
    public function it_pops_a_pending_job(): void
    {
        $this->driver->push('default', 'App\\Jobs\\SendEmail', '{"to":"a@b.com"}');

        $record = $this->driver->pop('default');

        self::assertNotNull($record);
        self::assertSame('default', $record->queue);
        self::assertSame('App\\Jobs\\SendEmail', $record->jobClass);
        self::assertSame('{"to":"a@b.com"}', $record->payload);
        self::assertSame(1, $record->attempts);
        self::assertSame(JobRecordStatus::Processing, $record->status);
    }

    #[Test]
    public function it_returns_null_when_popping_empty_queue(): void
    {
        $record = $this->driver->pop('default');

        self::assertNull($record);
    }

    #[Test]
    public function it_pops_only_from_specified_queue(): void
    {
        $this->driver->push('emails', 'App\\Jobs\\SendEmail', '{}');

        $record = $this->driver->pop('notifications');

        self::assertNull($record);
    }

    #[Test]
    public function it_does_not_pop_already_processing_jobs(): void
    {
        $this->driver->push('default', 'App\\Jobs\\Noop', '{}');

        $first = $this->driver->pop('default');
        self::assertNotNull($first);

        $second = $this->driver->pop('default');
        self::assertNull($second);
    }

    #[Test]
    public function it_pops_jobs_in_fifo_order(): void
    {
        $this->driver->push('default', 'App\\Jobs\\First', '{"order":1}');
        $this->driver->push('default', 'App\\Jobs\\Second', '{"order":2}');

        $first = $this->driver->pop('default');
        self::assertNotNull($first);
        self::assertSame('App\\Jobs\\First', $first->jobClass);
    }

    #[Test]
    public function it_does_not_pop_delayed_jobs_before_available_time(): void
    {
        $this->driver->push('default', 'App\\Jobs\\Delayed', '{}', 9999);

        $record = $this->driver->pop('default');

        self::assertNull($record);
    }

    #[Test]
    public function it_acknowledges_and_removes_job(): void
    {
        $id = $this->driver->push('default', 'App\\Jobs\\Noop', '{}');

        $record = $this->driver->pop('default');
        self::assertNotNull($record);

        $this->driver->acknowledge($id);

        $all = $this->driver->getAll();
        self::assertCount(0, $all);
    }

    #[Test]
    public function it_silently_ignores_acknowledging_unknown_job(): void
    {
        $this->driver->acknowledge('nonexistent-id');

        self::assertCount(0, $this->driver->getAll());
    }

    #[Test]
    public function it_rejects_job_and_marks_as_failed(): void
    {
        $id = $this->driver->push('default', 'App\\Jobs\\Noop', '{}');

        $record = $this->driver->pop('default');
        self::assertNotNull($record);

        $this->driver->reject($id, 'Something went wrong');

        $all = $this->driver->getAll();
        self::assertCount(1, $all);
        self::assertSame(JobRecordStatus::Failed, $all[0]->status);
    }

    #[Test]
    public function it_silently_ignores_rejecting_unknown_job(): void
    {
        $this->driver->reject('nonexistent-id', 'reason');

        self::assertCount(0, $this->driver->getAll());
    }

    #[Test]
    public function it_reports_size_of_pending_jobs_only(): void
    {
        $this->driver->push('default', 'App\\Jobs\\One', '{}');
        $this->driver->push('default', 'App\\Jobs\\Two', '{}');
        $this->driver->push('other', 'App\\Jobs\\Three', '{}');

        self::assertSame(2, $this->driver->size('default'));
        self::assertSame(1, $this->driver->size('other'));
        self::assertSame(0, $this->driver->size('empty'));
    }

    #[Test]
    public function it_excludes_processing_jobs_from_size(): void
    {
        $this->driver->push('default', 'App\\Jobs\\One', '{}');
        $this->driver->push('default', 'App\\Jobs\\Two', '{}');

        $this->driver->pop('default'); // transitions first job to Processing

        self::assertSame(1, $this->driver->size('default'));
    }

    #[Test]
    public function it_purges_all_jobs_from_specified_queue(): void
    {
        $this->driver->push('default', 'App\\Jobs\\One', '{}');
        $this->driver->push('default', 'App\\Jobs\\Two', '{}');
        $this->driver->push('other', 'App\\Jobs\\Three', '{}');

        $purged = $this->driver->purge('default');

        self::assertSame(2, $purged);
        self::assertSame(0, $this->driver->size('default'));
        self::assertSame(1, $this->driver->size('other'));
    }

    #[Test]
    public function it_returns_zero_when_purging_empty_queue(): void
    {
        $purged = $this->driver->purge('empty');

        self::assertSame(0, $purged);
    }

    #[Test]
    public function it_finds_jobs_by_status(): void
    {
        $id1 = $this->driver->push('default', 'App\\Jobs\\One', '{}');
        $id2 = $this->driver->push('default', 'App\\Jobs\\Two', '{}');

        // Pop one job to make it Processing
        $this->driver->pop('default');

        $pending = $this->driver->findByStatus(JobRecordStatus::Pending);
        self::assertCount(1, $pending);

        $processing = $this->driver->findByStatus(JobRecordStatus::Processing);
        self::assertCount(1, $processing);

        $failed = $this->driver->findByStatus(JobRecordStatus::Failed);
        self::assertCount(0, $failed);
    }

    #[Test]
    public function it_finds_failed_jobs_by_status(): void
    {
        $id = $this->driver->push('default', 'App\\Jobs\\Noop', '{}');
        $this->driver->pop('default');
        $this->driver->reject($id, 'error');

        $failed = $this->driver->findByStatus(JobRecordStatus::Failed);

        self::assertCount(1, $failed);
        self::assertSame($id, $failed[0]->id);
    }

    #[Test]
    public function get_all_returns_all_records(): void
    {
        $this->driver->push('default', 'App\\Jobs\\One', '{}');
        $this->driver->push('emails', 'App\\Jobs\\Two', '{}');

        $all = $this->driver->getAll();

        self::assertCount(2, $all);
    }

    #[Test]
    public function get_all_returns_empty_list_initially(): void
    {
        $all = $this->driver->getAll();

        self::assertSame([], $all);
    }

    #[Test]
    public function it_increments_attempts_on_each_pop(): void
    {
        $id = $this->driver->push('default', 'App\\Jobs\\Noop', '{}');

        $record = $this->driver->pop('default');
        self::assertNotNull($record);
        self::assertSame(1, $record->attempts);
    }
}
