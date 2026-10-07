<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Support;

use Override;
use Pulsar\DataProtection\DataPurgeInterface;
use Pulsar\DataProtection\RetentionPolicyInterface;

use function count;

/**
 * A purger that holds records and really removes them.
 *
 * Stubs returning a number prove a method was called; retention tests need to
 * prove that data is GONE, which is the property the whole subject is about.
 */
final class RecordingPurger implements DataPurgeInterface
{
    /** @var list<string> */
    private array $expired;

    /** @var list<string> */
    public private(set) array $purged = [];

    /**
     * @param list<string> $expired Identifiers of records past their retention window
     */
    public function __construct(array $expired)
    {
        $this->expired = $expired;
    }

    #[Override]
    public function purge(RetentionPolicyInterface $policy): int
    {
        $removed = count($this->expired);
        $this->purged = [...$this->purged, ...$this->expired];
        $this->expired = [];

        return $removed;
    }

    #[Override]
    public function countExpired(RetentionPolicyInterface $policy): int
    {
        return count($this->expired);
    }

    /**
     * What is still held after the run.
     *
     * @return list<string>
     */
    public function remaining(): array
    {
        return $this->expired;
    }
}
