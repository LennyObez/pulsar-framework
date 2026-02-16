<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Export\JsonLines\Internal;

use Pulsar\Api\Internal;
use RuntimeException;

use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fwrite;

use const LOCK_EX;
use const LOCK_UN;

/**
 * Writes data to a JSON Lines file with multi-process safety.
 *
 * Uses flock(LOCK_EX) to prevent interleaved writes from concurrent
 * PHP-FPM workers writing to the same file.
 */
#[Internal(reason: 'Shared utility for JSON Lines exporters')]
final readonly class JsonLinesFileWriter
{
    public function __construct(
        private string $filePath,
    ) {}

    /**
     * Append data to the JSON Lines file with exclusive locking.
     */
    public function write(string $data): void
    {
        $handle = fopen($this->filePath, 'a');

        if ($handle === false) {
            throw new RuntimeException("Failed to open file for writing: $this->filePath");
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $data);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}
