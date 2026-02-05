<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Runtime;

use Override;
use Pulsar\Deploy\Runtime\PhpRuntimeInterface;

use function in_array;

/**
 * Test double for PHP runtime introspection.
 */
final class StubPhpRuntime implements PhpRuntimeInterface
{
    /**
     * @param array<string, string|false> $iniValues
     * @param list<string>                $loadedExtensions
     * @param list<string>                $availableFunctions
     */
    public function __construct(
        private readonly array $iniValues = [],
        private readonly array $loadedExtensions = [],
        private readonly array $availableFunctions = [],
    ) {}

    #[Override]
    public function iniGet(string $key): string|false
    {
        return $this->iniValues[$key] ?? false;
    }

    #[Override]
    public function extensionLoaded(string $name): bool
    {
        return in_array($name, $this->loadedExtensions, true);
    }

    #[Override]
    public function functionExists(string $name): bool
    {
        return in_array($name, $this->availableFunctions, true);
    }
}
