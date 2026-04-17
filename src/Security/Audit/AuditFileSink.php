<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use JsonException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Security\Exception\SecurityException;
use Throwable;

use function chmod;
use function dirname;
use function fclose;
use function fflush;
use function file_exists;
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
final class AuditFileSink implements ChainableAuditSinkInterface, AuditChainStateAware
{
    /**
     * Directory permissions for auto-created directories.
     */
    private const int DIR_PERMISSIONS = 0o750;

    /**
     * F9.14: audit logs are forensic records — only the application
     * user (and a security-team operator with sudo) should be able to
     * read them. The file is chmod'd to `0o600` on first write,
     * tighter than the log-aggregator-friendly `0o640` used by
     * `FileSink`. Recommend operators also `chattr +a` the directory
     * on Linux to enforce append-only at the kernel level (out of
     * PHP's reach).
     */
    private const int FILE_PERMISSIONS = 0o600;

    /**
     * Bytes read from the tail of the file when looking up the last
     * entry. Sized at 64 KiB so a single audit record cannot legitimately
     * exceed it (entries are JSON Lines, well under that limit) and so
     * the read window cannot truncate the last line into something the
     * parser would read as malformed (F24.3 fix-2). The legacy 8 KiB
     * window made truncation possible on large metadata payloads.
     */
    private const int TAIL_READ_SIZE = 65_536;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $logPath,
        private readonly bool $fsync = false,
        ?LoggerInterface $logger = null,
    ) {
        // F24.3 fix-1: corruption diagnostics flow through PSR-3 so
        // operators can route them to the same structured pipeline as
        // every other security warning (incident reporter, ELK, etc.)
        // instead of being dumped to STDERR. NullLogger by default keeps
        // existing wiring working unchanged.
        $this->logger = $logger ?? new NullLogger();
    }

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

        $isNewFile = !file_exists($this->logPath);

        if ($this->fsync) {
            $this->writeWithFsync($line);
        } else {
            $result = file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

            if ($result === false) {
                throw SecurityException::auditWriteFailed(
                    sprintf('Could not write to audit log at "%s"', $this->logPath),
                );
            }
        }

        // F9.14: clamp permissions on first write so the audit log is
        // not world-readable under default umask. `@` swallows the
        // chmod warning on systems where the call is no-op (Windows).
        if ($isNewFile) {
            @chmod($this->logPath, self::FILE_PERMISSIONS);
        }
    }

    /**
     * Read the HMAC of the last written audit entry from the log file.
     *
     * Uses backward seek to efficiently read only the last line without
     * scanning the entire file. Returns null for empty, missing, or
     * corrupt files — never throws, per `ChainableAuditSinkInterface`
     * contract.
     *
     * Note: callers that hold tamper-evidence guarantees should consume
     * {@see chainState()} instead of `lastHmac()` alone — the legacy
     * `null` shape collapses "empty, fresh chain" and "non-empty,
     * corrupted chain" together, but `chainState()` distinguishes them
     * so the logger can fail closed on the second case (F24.3).
     */
    #[Override]
    public function lastHmac(): ?string
    {
        return $this->readLastEntry()['hmac'];
    }

    /**
     * F24.3: report whether the chain is empty, healthy, or corrupted.
     * `lastHmac()` collapses the last two into `null`; this method
     * separates them so `AuditLogger` can refuse to append to an
     * unverifiable chain instead of silently re-seeding over corruption.
     */
    #[Override]
    public function chainState(): AuditChainState
    {
        return $this->readLastEntry()['state'];
    }

    /**
     * Read the last entry from the log file in a single pass and return
     * both its HMAC and the discriminated chain state. Used by
     * {@see lastHmac()} and {@see chainState()} so the two methods
     * cannot disagree about what the file contains.
     *
     * @return array{hmac: ?string, state: AuditChainState}
     */
    private function readLastEntry(): array
    {
        if (!is_file($this->logPath)) {
            return ['hmac' => null, 'state' => AuditChainState::Empty];
        }

        try {
            $handle = fopen($this->logPath, 'rb');

            if ($handle === false) {
                $this->logger->warning(sprintf(
                    '[pulsar.AuditFileSink] could not open %s for chain-state read — refusing to append.',
                    $this->logPath,
                ));

                return ['hmac' => null, 'state' => AuditChainState::Corrupted];
            }

            $stat = fstat($handle);

            if ($stat === false) {
                fclose($handle);
                $this->logger->warning(sprintf(
                    '[pulsar.AuditFileSink] fstat() failed on %s — refusing to append.',
                    $this->logPath,
                ));

                return ['hmac' => null, 'state' => AuditChainState::Corrupted];
            }

            if ($stat['size'] === 0) {
                fclose($handle);

                return ['hmac' => null, 'state' => AuditChainState::Empty];
            }

            $fileSize = $stat['size'];

            // 64 KiB tail window: well above the size of any single
            // legitimate JSON-Lines audit entry, so a malformed read
            // here always means corruption rather than truncation.
            /** @var positive-int $readSize */
            $readSize = min($fileSize, self::TAIL_READ_SIZE);
            fseek($handle, -$readSize, SEEK_END);
            $chunk = fread($handle, $readSize);
            fclose($handle);

            if (!is_string($chunk) || $chunk === '') {
                $this->logger->warning(sprintf(
                    '[pulsar.AuditFileSink] tail read of %s returned empty chunk — file may be unreadable.',
                    $this->logPath,
                ));

                return ['hmac' => null, 'state' => AuditChainState::Corrupted];
            }

            // Trim trailing newline(s), then find the last complete line
            $chunk = trim($chunk, "\n\r");
            $lastNewline = strrpos($chunk, "\n");
            $lastLine = $lastNewline !== false ? substr($chunk, $lastNewline + 1) : $chunk;
            $lastLine = trim($lastLine);

            if ($lastLine === '') {
                $this->logger->warning(sprintf(
                    '[pulsar.AuditFileSink] last line of %s is blank — chain corrupted.',
                    $this->logPath,
                ));

                return ['hmac' => null, 'state' => AuditChainState::Corrupted];
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($lastLine, true);

            if (!is_array($data) || !isset($data['hmac']) || !is_string($data['hmac'])) {
                $this->logger->warning(sprintf(
                    '[pulsar.AuditFileSink] last entry of %s is malformed or missing hmac — chain corrupted.',
                    $this->logPath,
                ));

                return ['hmac' => null, 'state' => AuditChainState::Corrupted];
            }

            return ['hmac' => $data['hmac'], 'state' => AuditChainState::Healthy];
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                '[pulsar.AuditFileSink] chain-state read failed for %s: %s — refusing to append.',
                $this->logPath,
                $e->getMessage(),
            ));

            return ['hmac' => null, 'state' => AuditChainState::Corrupted];
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

            // fsync forces OS buffers to disk — the side effect is the
            // whole point. Psalm's UnusedFunctionCall sees only the
            // bool return; we check it so the durability guarantee is
            // not silently lost on a kernel-level fsync failure.
            if (!fsync($handle)) {
                throw SecurityException::auditWriteFailed(
                    sprintf('fsync failed on audit log at "%s"', $this->logPath),
                );
            }
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
