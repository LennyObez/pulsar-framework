<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function is_string;

/**
 * A container for a collection of resources.
 *
 * @see https://www.hl7.org/fhir/bundle.html
 */
#[Api(since: '1.0.0')]
final readonly class Bundle extends FhirResource
{
    /**
     * @param list<BundleEntry> $entry Entries in the bundle
     * @param list<BundleLink> $link  Links related to this bundle
     */
    public function __construct(
        ?string $id = null,
        ?Meta $meta = null,
        ?string $language = null,
        public ?string $type = null,
        public ?int $total = null,
        public array $link = [],
        public array $entry = [],
        public ?string $timestamp = null,
    ) {
        parent::__construct(ResourceType::Bundle, $id, $meta, $language);
    }

    #[Override]
    public function toArray(): array
    {
        $data = $this->baseToArray();

        if ($this->type !== null) {
            $data['type'] = $this->type;
        }

        if ($this->total !== null) {
            $data['total'] = $this->total;
        }

        if ($this->link !== []) {
            $data['link'] = array_map(
                static fn(BundleLink $l): array => $l->toArray(),
                $this->link,
            );
        }

        if ($this->entry !== []) {
            $data['entry'] = array_map(
                static fn(BundleEntry $e): array => $e->toArray(),
                $this->entry,
            );
        }

        if ($this->timestamp !== null) {
            $data['timestamp'] = $this->timestamp;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $metaData */
        $metaData = $data['meta'] ?? null;
        /** @var list<array<string, string>> $linkList */
        $linkList = $data['link'] ?? [];
        /** @var list<array<string, mixed>> $entryList */
        $entryList = $data['entry'] ?? [];

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            total: isset($data['total']) && is_numeric($data['total']) ? (int) $data['total'] : null,
            link: array_values(array_map(
                static fn(array $l): BundleLink => BundleLink::fromArray($l),
                $linkList,
            )),
            entry: array_values(array_map(
                static fn(array $e): BundleEntry => BundleEntry::fromArray($e),
                $entryList,
            )),
            timestamp: is_string($data['timestamp'] ?? null) ? $data['timestamp'] : null,
        );
    }
}
