<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

use function count;

/**
 * Immutable collection of JSON-LD structured data objects.
 */
#[Api(since: '1.0.0')]
final readonly class JsonLdCollection
{
    /**
     * @param list<array<string, mixed>> $items JSON-LD objects (each is an associative array)
     */
    public function __construct(
        public array $items = [],
    ) {}

    /**
     * Render all JSON-LD items as a single HTML script block.
     */
    public function toScript(): string
    {
        if ($this->items === []) {
            return '';
        }

        $graph = count($this->items) === 1
            ? $this->items[0]
            : ['@graph' => $this->items];

        $json = json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
