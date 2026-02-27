<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

/**
 * Generator for robots.txt content.
 *
 * @psalm-api Public binding contract; implemented by RobotsTxtGenerator and
 *            consumed by /robots.txt route.
 */
#[Api(since: '1.0.0')]
interface RobotsTxtGeneratorInterface
{
    /**
     * Generate the robots.txt content for the given base URL.
     */
    public function generate(string $baseUrl, ?string $tenantId = null): string;
}
