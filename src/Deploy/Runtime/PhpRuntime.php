<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Runtime;

use Override;
use Pulsar\Api\Internal;

use function extension_loaded;
use function function_exists;
use function ini_get;

/**
 * Default implementation delegating to real PHP runtime functions.
 */
#[Internal]
final readonly class PhpRuntime implements PhpRuntimeInterface
{
    #[Override]
    public function iniGet(string $key): string|false
    {
        return ini_get($key);
    }

    #[Override]
    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    #[Override]
    public function functionExists(string $name): bool
    {
        return function_exists($name);
    }
}
