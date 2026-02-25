<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\LiveCss;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;

use function base64_encode;
use function hash;

/**
 * Computes SHA-256 hashes for CSP style-src directives.
 *
 * @psalm-api Bound to CspHashComputerInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use CspHashComputerInterface for public API')]
final readonly class CspHashComputer implements CspHashComputerInterface
{
    public function computeHash(string $styleContent): string
    {
        $rawHash = hash('sha256', $styleContent, true);

        return 'sha256-' . base64_encode($rawHash);
    }
}
