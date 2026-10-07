<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

/**
 * A link related to a Bundle (e.g. self, next, prev for paging).
 *
 * @see https://www.hl7.org/fhir/bundle-definitions.html#Bundle.link
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BundleLink
{
    public function __construct(
        public string $relation,
        public string $url,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'relation' => $this->relation,
            'url' => $this->url,
        ];
    }

    /**
     * @param array<string, string> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            relation: $data['relation'],
            url: $data['url'],
        );
    }
}
