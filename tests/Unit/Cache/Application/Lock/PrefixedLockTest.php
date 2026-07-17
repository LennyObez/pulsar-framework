<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\ArrayLock;
use Pulsar\Cache\Application\Lock\PrefixedLock;

#[CoversClass(PrefixedLock::class)]
final class PrefixedLockTest extends TestCase
{
    #[Test]
    public function twoPrefixedLocksDoNotContendOnTheSameLogicalResource(): void
    {
        // One shared lock backend, two pools with distinct prefixes: acquiring
        // the SAME logical resource under each must not contend — the prefix
        // isolates them, exactly as the data keys are isolated.
        $backend = new ArrayLock();
        $poolA = new PrefixedLock($backend, 'app_a.');
        $poolB = new PrefixedLock($backend, 'app_b.');

        $handleA = $poolA->acquire('cms_page_lock.home', 30, 0);

        // Pool B acquires the same logical resource without waiting on pool A.
        $handleB = $poolB->acquire('cms_page_lock.home', 30, 0);

        self::assertTrue($poolA->release($handleA));
        self::assertTrue($poolB->release($handleB));
    }

    #[Test]
    public function theSameLogicalResourceUnderOnePrefixStillContends(): void
    {
        // Isolation is per-prefix, not blanket: within one pool the lock still
        // serialises access to a logical resource.
        $pool = new PrefixedLock(new ArrayLock(), 'app_a.');

        $pool->acquire('resource', 30, 0);

        $this->expectException(LockAcquisitionException::class);

        $pool->acquire('resource', 30, 0);
    }

    #[Test]
    public function refreshAndReleasePassTheHandleThrough(): void
    {
        $backend = new ArrayLock();
        $pool = new PrefixedLock($backend, 'p.');

        $handle = $pool->acquire('r', 30, 0);

        self::assertTrue($pool->refresh($handle, 60));
        self::assertTrue($pool->release($handle));
    }
}
