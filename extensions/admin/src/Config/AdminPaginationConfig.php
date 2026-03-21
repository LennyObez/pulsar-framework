<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Pagination configuration for the admin panel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdminPaginationConfig
{
    public function __construct(
        public int $defaultPerPage,
        public int $maxPerPage,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int $defaultPerPage */
        $defaultPerPage = $data['default_per_page'] ?? 25;
        /** @var int $maxPerPage */
        $maxPerPage = $data['max_per_page'] ?? 100;

        return new self(
            defaultPerPage: $defaultPerPage,
            maxPerPage: $maxPerPage,
        );
    }
}
