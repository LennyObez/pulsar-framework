<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * Renders a route path to HTML for static site generation.
 */
#[Api(since: '1.0.0')]
interface PageRendererInterface
{
    /**
     * Render the given route path to an HTML string.
     *
     * @throws RuntimeException If the path cannot be rendered
     */
    public function render(string $path): string;
}
