<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * Value object representing a single breadcrumb trail entry.
 *
 * @psalm-api Public DTO returned from BreadcrumbGeneratorInterface; consumed
 *            by content templates.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BreadcrumbItem
{
    /**
     * @param string $label Display text for this breadcrumb
     * @param string $url URL this breadcrumb links to
     * @param bool $isCurrent Whether this is the active/current page
     */
    public function __construct(
        public string $label,
        public string $url,
        public bool $isCurrent,
    ) {}
}
