<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use function implode;
use function in_array;
use function sprintf;

/**
 * One thing that can block a merge or a release, and every name it answers to.
 *
 * A gate has more than one name because the repository names it more than once: the
 * contributor runs `composer class-shape`, the workflow step runs
 * `tools/ci/assert-substitutability-and-immutability.php`, and those are the same gate.
 * Recording the aliases rather than picking a winner means a negative test may declare
 * whichever name its author was looking at, and the enumeration still resolves it.
 */
final readonly class Gate
{
    /**
     * @param string       $id      The canonical name: the script path where one exists,
     *                              because a path survives a composer script being renamed.
     * @param list<string> $aliases Every other name that refers to this same gate.
     * @param string       $origin  How the gate was discovered, in words a reviewer can
     *                              check against the file named.
     */
    public function __construct(
        public string $id,
        public array $aliases,
        public string $origin,
    ) {}

    /**
     * Does some declaration name this gate?
     */
    public function isNamedBy(string $declaration): bool
    {
        return $declaration === $this->id || in_array($declaration, $this->aliases, true);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return [$this->id, ...$this->aliases];
    }

    public function describe(): string
    {
        $also = $this->aliases === [] ? '' : sprintf(' (also: %s)', implode(', ', $this->aliases));

        return sprintf('%s%s — %s', $this->id, $also, $this->origin);
    }
}
