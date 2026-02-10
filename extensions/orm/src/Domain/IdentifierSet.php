<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function array_values;
use function in_array;

/**
 * Unique set of validated identifiers (for column lists, etc.).
 */
#[Api(since: '1.0.0')]
final readonly class IdentifierSet
{
    /** @var list<string> */
    private array $items;

    /**
     * @param list<string> $identifiers
     */
    private function __construct(array $identifiers)
    {
        $unique = [];
        foreach ($identifiers as $id) {
            if (!in_array($id, $unique, true)) {
                $unique[] = $id;
            }
        }
        $this->items = $unique;
    }

    /**
     * @param list<string> $identifiers
     */
    #[NoDiscard]
    public static function of(array $identifiers): self
    {
        foreach ($identifiers as $id) {
            IdentifierValidator::validate($id);
        }

        return new self($identifiers);
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function contains(string $identifier): bool
    {
        return in_array($identifier, $this->items, true);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
