<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Runtime;

use Override;
use Pulsar\Api\Internal;

use function is_readable;

/**
 * Default implementation delegating to real filesystem functions.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class FilesystemReader implements FilesystemReaderInterface
{
    #[Override]
    public function isReadable(string $path): bool
    {
        return is_readable($path);
    }
}
