<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Computes CSP-compatible hashes for inline style blocks.
 *
 * @psalm-api Public binding contract; implemented by CspHashComputer and
 *            consumed by LiveCssService and the inline style renderer.
 */
#[Api(since: '1.0.0')]
interface CspHashComputerInterface
{
    /**
     * Compute a SHA-256 hash suitable for CSP style-src directives.
     *
     * @return string Hash in format "sha256-{base64hash}"
     */
    public function computeHash(string $styleContent): string;
}
