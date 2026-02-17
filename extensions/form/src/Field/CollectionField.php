<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field;

use Closure;
use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Http\Validation\RuleInterface;

use function is_array;

/**
 * Collection field for repeatable fieldsets.
 *
 * Allows dynamic add/remove of repeated field groups
 * (e.g., multiple addresses, phone numbers).
 */
#[Api(since: '1.0.0')]
final class CollectionField implements FieldInterface
{
    /** @var list<FieldInterface> */
    private array $entries = [];

    private mixed $value = null;

    /**
     * @param Closure(): FieldInterface $prototype Factory for creating new entries
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly Closure $prototype,
        private readonly int $minEntries = 0,
        private readonly ?int $maxEntries = null,
    ) {}

    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function getLabel(): string
    {
        return $this->label;
    }

    #[Override]
    public function getType(): string
    {
        return 'collection';
    }

    #[Override]
    public function getValue(): mixed
    {
        return $this->value;
    }

    #[Override]
    public function setValue(mixed $value): void
    {
        $this->value = $value;

        if (is_array($value)) {
            $this->entries = [];

            foreach ($value as $index => $entryValue) {
                $entry = ($this->prototype)();
                $entry->setValue($entryValue);
                $this->entries[] = $entry;
            }
        }
    }

    #[Override]
    public function isRequired(): bool
    {
        return $this->minEntries > 0;
    }

    #[Override]
    public function isDisabled(): bool
    {
        return false;
    }

    /**
     * @return array<string, string|bool>
     */
    #[Override]
    public function getAttributes(): array
    {
        return [];
    }

    /**
     * @return list<RuleInterface>
     */
    #[Override]
    public function getRules(): array
    {
        return [];
    }

    /**
     * Add a new entry to the collection.
     */
    public function addEntry(): FieldInterface
    {
        $entry = ($this->prototype)();
        $this->entries[] = $entry;

        return $entry;
    }

    /**
     * Remove an entry by index.
     */
    public function removeEntry(int $index): void
    {
        /** @var array<int, FieldInterface> $remaining */
        $remaining = $this->entries;
        unset($remaining[$index]);

        $this->entries = [];

        foreach ($remaining as $entry) {
            $this->entries[] = $entry;
        }
    }

    /**
     * @return list<FieldInterface>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getMinEntries(): int
    {
        return $this->minEntries;
    }

    public function getMaxEntries(): ?int
    {
        return $this->maxEntries;
    }

    public function getId(): string
    {
        return 'collection-' . $this->name;
    }

    public function getErrorId(): string
    {
        return $this->getId() . '-error';
    }
}
