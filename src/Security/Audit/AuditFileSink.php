<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use function dirname;

use const FILE_APPEND;

use function file_put_contents;
use function is_dir;
use function json_encode;

use JsonException;

use const LOCK_EX;

use function mkdir;

use Override;
use Pulsar\Security\Exception\SecurityException;

use function sprintf;

/**
 * Append-only JSON Lines audit log file sink.
 *
 * Each entry is written as a single JSON line with `LOCK_EX` for
 * concurrent write safety. The log directory is created with 0750
 * permissions if it does not exist.
 */
final class AuditFileSink implements AuditSinkInterface
{
    /**
     * Directory permissions for auto-created directories.
     */
    private const int DIR_PERMISSIONS = 0o750;

    public function __construct(
        private readonly string $logPath,
    ) {}

    /**
     * @throws JsonException
     */
    #[Override]
    public function write(AuditEntry $entry): void
    {
        $this->ensureDirectory();

        $line = json_encode(
            $entry->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";

        $result = file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw SecurityException::auditWriteFailed(
                sprintf('Could not write to audit log at "%s"', $this->logPath),
            );
        }
    }

    /**
     * Ensure the log directory exists with appropriate permissions.
     */
    private function ensureDirectory(): void
    {
        $dir = dirname($this->logPath);

        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, self::DIR_PERMISSIONS, true) && !is_dir($dir)) {
            throw SecurityException::auditWriteFailed(
                sprintf('Could not create audit log directory "%s"', $dir),
            );
        }
    }
}
