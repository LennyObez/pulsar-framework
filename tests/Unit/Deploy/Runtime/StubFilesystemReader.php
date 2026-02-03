<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Runtime;

use Override;
use Pulsar\Deploy\Runtime\FilesystemReaderInterface;

/**
 * Test double for filesystem readability checks.
 */
final class StubFilesystemReader implements FilesystemReaderInterface
{
    /**
     * @param array<string, bool> $readableMap Path → readable mapping
     */
    public function __construct(
        private readonly array $readableMap = [],
    ) {}

    #[Override]
    public function isReadable(string $path): bool
    {
        return $this->readableMap[$path] ?? false;
    }
}
