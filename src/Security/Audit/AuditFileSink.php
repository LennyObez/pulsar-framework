<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use JsonException;
use Override;
use Pulsar\Security\Exception\SecurityException;
use Throwable;

use function dirname;
use function error_log;
use function fclose;
use function fflush;
use function file_put_contents;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function fsync;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function sprintf;
use function strlen;
use function strrpos;
use function substr;
use function trim;

use const FILE_APPEND;
use const LOCK_EX;
use const LOCK_UN;
use const SEEK_END;

/**
 * Append-only JSON Lines audit log file sink.
 *
 * Each entry is written as a single JSON line with `LOCK_EX` for
 * concurrent write safety. The log directory is created with 0750
 * permissions if it does not exist.
 */
final class AuditFileSink implements ChainableAuditSinkInterface
{
    /**
     * Directory permissions for auto-created directories.
     */
    private const int DIR_PERMISSIONS = 0o750;

    public function __construct(
        private readonly string $logPath,
        private readonly bool $fsync = false,
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

        if ($this->fsync) {
            $this->writeWithFsync($line);

            return;
        }

        $result = file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw SecurityException::auditWriteFailed(
                sprintf('Could not write to audit log at "%s"', $this->logPath),
            );
        }
    }

    /**
     * Read the HMAC of the last written audit entry from the log file.
     *
     * Uses backward seek to efficiently read only the last line without
     * scanning the entire file. Returns null for empty, missing, or
     * corrupt files: never throws, per `ChainableAuditSinkInterface`
     * contract — the caller re-seeds the chain on null.
     *
     * Silently returning null for a corrupt non-empty file would break
     * the tamper-evidence guarantee of the chain (the next write would
     * start a fresh chain instead of extending the broken one), so any
     * unexpected exception is logged via `error_log()` before falling
     * back to null. That gives operators a chance to react before the
     * regulated audit backlog piles up (H-4 audit response).
     */
    #[Override]
    public function lastHmac(): ?string
    {
        if (!is_file($this->logPath)) {
            return null;
        }

        try {
            $handle = fopen($this->logPath, 'rb');

            if ($handle === false) {
                return null;
            }

            $stat = fstat($handle);

            if ($stat === false || $stat['size'] === 0) {
                fclose($handle);

                return null;
            }

            $fileSize = $stat['size'];

            // Read up to 8KB from the end: enough for one JSONL audit entry
            /** @var positive-int $readSize */
            $readSize = min($fileSize, 8192);
            fseek($handle, -$readSize, SEEK_END);
            $chunk = fread($handle, $readSize);
            fclose($handle);

            if (!is_string($chunk) || $chunk === '') {
                return null;
            }

            // Trim trailing newline(s), then find the last complete line
            $chunk = trim($chunk, "\n\r");
            $lastNewline = strrpos($chunk, "\n");
            $lastLine = $lastNewline !== false ? substr($chunk, $lastNewline + 1) : $chunk;
            $lastLine = trim($lastLine);

            if ($lastLine === '') {
                return null;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($lastLine, true);

            if (!is_array($data) || !isset($data['hmac']) || !is_string($data['hmac'])) {
                return null;
            }

            return $data['hmac'];
        } catch (Throwable $e) {
            error_log(sprintf(
                '[pulsar.AuditFileSink] lastHmac() failed for %s: %s — audit chain will be re-seeded.',
                $this->logPath,
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Write with explicit fsync to ensure durability for regulated deployments.
     *
     * Opens the file with an exclusive lock, appends the line, calls fsync()
     * to flush OS buffers to disk, then releases the lock.
     */
    private function writeWithFsync(string $line): void
    {
        $handle = fopen($this->logPath, 'ab');

        if ($handle === false) {
            throw SecurityException::auditWriteFailed(
                sprintf('Could not open audit log at "%s"', $this->logPath),
            );
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw SecurityException::auditWriteFailed(
                    sprintf('Could not acquire lock on audit log at "%s"', $this->logPath),
                );
            }

            $written = fwrite($handle, $line);

            if ($written === false || $written !== strlen($line)) {
                throw SecurityException::auditWriteFailed(
                    sprintf('Incomplete write to audit log at "%s"', $this->logPath),
                );
            }

            fflush($handle);
            fsync($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
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
