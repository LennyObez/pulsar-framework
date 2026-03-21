<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;

/**
 * Represents an extension discovered from a Composer package.
 *
 * Holds the Composer package name and the FQCN of the extension class
 * declared via `extra.pulsar.extension` in composer.json.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiscoveredExtension
{
    /**
     * @param string $packageName Composer package name (e.g. "vendor/package")
     * @param class-string<ExtensionInterface> $extensionClass FQCN of the extension
     */
    public function __construct(
        public string $packageName,
        public string $extensionClass,
    ) {}
}
