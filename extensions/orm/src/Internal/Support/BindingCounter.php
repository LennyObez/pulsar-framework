<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Support;

use NoDiscard;
use Pulsar\Api\Internal;

use function sprintf;

/**
 * Generates unique named binding placeholders for parameterized queries.
 */
#[Internal]
final class BindingCounter
{
    private int $counter = 0;

    /**
     * Generate the next unique binding name.
     */
    #[NoDiscard]
    public function next(string $prefix = 'p'): string
    {
        return sprintf('%s%d', $prefix, $this->counter++);
    }

    /**
     * Reset the counter (for reuse across query compilations).
     */
    public function reset(): void
    {
        $this->counter = 0;
    }

    public function current(): int
    {
        return $this->counter;
    }
}
