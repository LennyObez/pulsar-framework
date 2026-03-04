<?php

declare(strict_types=1);

namespace Pulsar\Documentation;

use Pulsar\Api\Api;

/**
 * Represents a single documentation page within a version.
 */
#[Api(since: '1.0.0')]
final readonly class DocPage
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $content,
        public string $version,
        public ?string $section = null,
        public int $order = 0,
        public ?string $description = null,
    ) {}

    /**
     * Full URL path for this documentation page.
     */
    public function url(): string
    {
        $path = '/docs/' . $this->version;

        if ($this->section !== null) {
            $path .= '/' . $this->section;
        }

        return $path . '/' . $this->slug;
    }
}
