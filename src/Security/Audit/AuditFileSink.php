<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use function dirname;
use function fclose;
use function feof;

use const FILE_APPEND;

use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;

use JsonException;

use const LOCK_EX;

use function mkdir;

use Override;
use Pulsar\Security\Exception\SecurityException;

use const SEEK_END;

use function sprintf;
use function strrpos;
use function substr;
use function trim;

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
     * Read the HMAC of the last written audit entry from the log file.
     *
     * Uses backward seek to efficiently read only the last line without
     * scanning the entire file. Returns null for empty, missing, or
     * corrupt files — never throws.
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

            // Read up to 8KB from the end — enough for one JSONL audit entry
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
        } catch (\Throwable) {
            return null;
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
