<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use Override;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogFormatter;
use Pulsar\Observability\Log\LogSinkInterface;

use function chmod;
use function dirname;
use function file_exists;
use function is_dir;

/**
 * Log sink that appends JSON lines to a file.
 *
 * Creates the directory if missing. Uses LOCK_EX for concurrent safety.
 *
 * F4.5: log files routinely contain accidental PII / PHI / credentials
 * leaked through error context, request bodies surfaced in stack traces,
 * etc. The previous defaults (`0o775` directory, default umask file)
 * exposed those records to every system user. We now create the
 * directory `0o750` (owner rwx, group rx, world none) and the file
 * `0o640` (owner rw, group r, world none). Operators wanting tighter
 * `0o600` files can enforce that via the host umask; we cannot easily
 * downgrade further without breaking shared-group log collection.
 */
final readonly class FileSink implements LogSinkInterface
{
    private const int DIRECTORY_MODE = 0o750;
    private const int FILE_MODE = 0o640;

    private LogFormatter $formatter;

    public function __construct(
        private string $path,
        ?LogFormatter $formatter = null,
    ) {
        $this->formatter = $formatter ?? new LogFormatter();
    }

    #[Override]
    public function write(LogEntry $entry): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            // Trailing `@` swallows the inner mkdir warnings — caller
            // sees the failure via the subsequent file_put_contents
            // path-check exception (Logger::log catches and falls back).
            @mkdir($directory, self::DIRECTORY_MODE, true);
        }

        $isNewFile = !file_exists($this->path);

        $line = $this->formatter->format($entry);
        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);

        // Apply restrictive permissions on first write. Subsequent
        // writes inherit them; an operator that explicitly tightens
        // the mode further is left intact.
        if ($isNewFile) {
            @chmod($this->path, self::FILE_MODE);
        }
    }
}
