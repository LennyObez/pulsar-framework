<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * A container for a collection of resources.
 *
 * @see https://www.hl7.org/fhir/bundle.html
 * @api
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
     * @param array{
     *     id?: string|null,
     *     meta?: array<string, mixed>|null,
     *     language?: string|null,
     *     type?: string|null,
     *     total?: int|null,
     *     link?: list<array<string, string>>,
     *     entry?: list<array<string, mixed>>,
     *     timestamp?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $metaData = $data['meta'] ?? null;

        return new self(
            id: $data['id'] ?? null,
            meta: $metaData !== null ? Meta::fromArray($metaData) : null,
            language: $data['language'] ?? null,
            type: $data['type'] ?? null,
            total: $data['total'] ?? null,
            link: array_values(array_map(
                static fn(array $l): BundleLink => BundleLink::fromArray($l),
                $data['link'] ?? [],
            )),
            entry: array_values(array_map(
                static fn(array $e): BundleEntry => BundleEntry::fromArray($e),
                $data['entry'] ?? [],
            )),
            timestamp: $data['timestamp'] ?? null,
        );
    }
}
