<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Chain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Chain\JobChain;
use Pulsar\Queue\QueueableInterface;

#[CoversClass(JobChain::class)]
final class JobChainTest extends TestCase
{
    #[Test]
    public function constructorStoresIdAndJobs(): void
    {
        $job1 = $this->createStub(QueueableInterface::class);
        $job2 = $this->createStub(QueueableInterface::class);

        $chain = new JobChain('chain-001', [$job1, $job2]);

        self::assertSame('chain-001', $chain->id);
        self::assertCount(2, $chain->jobs);
    }

    #[Test]
    public function countReturnsNumberOfJobs(): void
    {
        $chain = new JobChain('chain-002', [
            $this->createStub(QueueableInterface::class),
            $this->createStub(QueueableInterface::class),
            $this->createStub(QueueableInterface::class),
        ]);

        self::assertSame(3, $chain->count());
    }

    #[Test]
    public function countReturnsZeroForEmptyChain(): void
    {
        $chain = new JobChain('chain-empty', []);

        self::assertSame(0, $chain->count());
    }

    #[Test]
    public function jobAtReturnsJobAtValidIndex(): void
    {
        $job1 = $this->createStub(QueueableInterface::class);
        $job2 = $this->createStub(QueueableInterface::class);

        $chain = new JobChain('chain-003', [$job1, $job2]);

        self::assertSame($job1, $chain->jobAt(0));
        self::assertSame($job2, $chain->jobAt(1));
    }

    #[Test]
    public function jobAtReturnsNullForInvalidIndex(): void
    {
        $chain = new JobChain('chain-004', [
            $this->createStub(QueueableInterface::class),
        ]);

        self::assertNull($chain->jobAt(5));
        self::assertNull($chain->jobAt(-1));
    }

    #[Test]
    public function jobClassesReturnsFullyQualifiedClassNames(): void
    {
        $job1 = $this->createStub(QueueableInterface::class);
        $job2 = $this->createStub(QueueableInterface::class);

        $chain = new JobChain('chain-005', [$job1, $job2]);

        $classes = $chain->jobClasses();

        self::assertCount(2, $classes);
        foreach ($classes as $class) {
            self::assertIsString($class);
        }
    }

    #[Test]
    public function jobClassesReturnsEmptyArrayForEmptyChain(): void
    {
        $chain = new JobChain('chain-006', []);

        self::assertSame([], $chain->jobClasses());
    }
}
