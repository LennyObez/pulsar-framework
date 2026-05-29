<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Override;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_map;
use function is_array;

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
        $links = $data['link'] ?? null;
        $entries = $data['entry'] ?? null;
        $total = $data['total'] ?? null;

        $linkList = [];
        if (is_array($links)) {
            foreach ($links as $l) {
                if (is_array($l)) {
                    $linkList[] = BundleLink::fromArray($l);
                }
            }
        }

        $entryList = [];
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if (is_array($e)) {
                    $entryList[] = BundleEntry::fromArray($e);
                }
            }
        }

        return new self(
            id: Coerce::nullableString($data['id'] ?? null),
            meta: is_array($metaData) ? Meta::fromArray($metaData) : null,
            language: Coerce::nullableString($data['language'] ?? null),
            type: Coerce::nullableString($data['type'] ?? null),
            total: Coerce::nullableInt($total),
            link: $linkList,
            entry: $entryList,
            timestamp: Coerce::nullableString($data['timestamp'] ?? null),
        );
    }
}
