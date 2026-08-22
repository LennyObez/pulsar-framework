<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function hash_equals;
use function in_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function realpath;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function time;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Manages application maintenance mode state.
 *
 * Maintenance mode is signaled by the presence of a JSON file
 * at a configurable path. The file contains metadata including
 * a bypass secret for allowing specific clients through.
 *
 * The storage path is validated at construction time to prevent
 * path traversal. Only the hardcoded filename "maintenance.json"
 * is ever written or deleted.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MaintenanceMode
{
    private const string FILENAME = 'maintenance.json';

    private string $resolvedStoragePath;

    public function __construct(
        string $storagePath,
    ) {
        $this->resolvedStoragePath = self::validateStoragePath($storagePath);
    }

    /**
     * Enable maintenance mode.
     *
     * @param list<string> $allowedIps IP addresses that bypass maintenance mode
     */
    public function enable(
        ?string $secret = null,
        ?string $message = null,
        int $retryAfter = 60,
        array $allowedIps = [],
    ): void {
        $data = [
            'enabled' => true,
            'time' => time(),
            'secret' => $secret,
            'message' => $message ?? 'Application is currently undergoing maintenance.',
            'retry_after' => $retryAfter,
            'allowed_ips' => $allowedIps,
        ];

        file_put_contents(
            $this->filePath(),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Disable maintenance mode by removing the maintenance file.
     *
     * The file path is fully controlled: constructed from a validated
     * storage directory + a hardcoded filename constant. No user input
     * reaches the unlink() call.
     */
    public function disable(): void
    {
        $path = $this->filePath();

        if (!file_exists($path)) {
            return;
        }

        // Defense-in-depth: verify the resolved path still points
        // inside the validated storage directory before deletion.
        $realFile = realpath($path);

        if ($realFile === false) {
            return;
        }

        if (!str_starts_with($realFile, $this->resolvedStoragePath . DIRECTORY_SEPARATOR)
            && $realFile !== $this->resolvedStoragePath . DIRECTORY_SEPARATOR . self::FILENAME
        ) {
            return;
        }

        // @safety: path is constructed from validated storage dir + hardcoded filename
        unlink($realFile); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }

    /**
     * Check if maintenance mode is active.
     */
    #[NoDiscard]
    public function isActive(): bool
    {
        return file_exists($this->filePath());
    }

    /**
     * Get the maintenance mode payload.
     *
     * @return array{enabled: bool, time: int, secret: string|null, message: string, retry_after: int, allowed_ips: list<string>}|null
     */
    #[NoDiscard]
    public function payload(): ?array
    {
        $path = $this->filePath();

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        /** @var array{enabled: bool, time: int, secret: string|null, message: string, retry_after: int, allowed_ips: list<string>} */
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Check if a secret matches the bypass secret.
     */
    #[NoDiscard]
    public function checkSecret(string $secret): bool
    {
        $payload = $this->payload();

        if ($payload === null || $payload['secret'] === null) {
            return false;
        }

        return hash_equals($payload['secret'], $secret);
    }

    /**
     * Check if an IP address is allowed to bypass maintenance mode.
     */
    #[NoDiscard]
    public function isIpAllowed(string $ip): bool
    {
        $payload = $this->payload();

        if ($payload === null) {
            return false;
        }

        return in_array($ip, $payload['allowed_ips'], true);
    }

    /**
     * Validate and resolve the storage path at construction time.
     *
     * @throws InvalidArgumentException If the path contains traversal sequences or does not exist
     */
    private static function validateStoragePath(string $storagePath): string
    {
        if (str_contains($storagePath, '..')) {
            throw new InvalidArgumentException(sprintf(
                'Storage path must not contain path traversal sequences: "%s"',
                $storagePath,
            ));
        }

        if (!is_dir($storagePath)) {
            throw new InvalidArgumentException(sprintf(
                'Storage path does not exist or is not a directory: "%s"',
                $storagePath,
            ));
        }

        $resolved = realpath($storagePath);

        if ($resolved === false) {
            throw new InvalidArgumentException(sprintf(
                'Cannot resolve storage path: "%s"',
                $storagePath,
            ));
        }

        return $resolved;
    }

    private function filePath(): string
    {
        return $this->resolvedStoragePath . DIRECTORY_SEPARATOR . self::FILENAME;
    }
}
