<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Handler;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\Exception\SecurityException;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function sprintf;
use function tempnam;
use function time;
use function unlink;

use const LOCK_EX;

/**
 * File-based session handler.
 *
 * Stores session data as files in a configurable directory with atomic writes.
 */
#[Internal]
final class FileHandler implements SessionHandlerInterface
{
    private string $savePath = '';

    #[Override]
    public function open(string $path, string $name): bool
    {
        $this->savePath = $path;

        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0o700, true);
        }

        return is_dir($this->savePath);
    }

    #[Override]
    public function close(): bool
    {
        return true;
    }

    #[Override]
    public function read(string $id): string
    {
        $file = $this->sessionFile($id);

        if (!is_file($file)) {
            return '';
        }

        $data = file_get_contents($file);

        return $data !== false ? $data : '';
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        $file = $this->sessionFile($id);
        $tmp = tempnam($this->savePath, 'sess_tmp_');

        if ($tmp === false) {
            return false;
        }

        if (file_put_contents($tmp, $data, LOCK_EX) === false) {
            unlink($tmp);
            return false;
        }

        return rename($tmp, $file);
    }

    #[Override]
    public function destroy(string $id): bool
    {
        $file = $this->sessionFile($id);

        if (is_file($file)) {
            return unlink($file);
        }

        return true;
    }

    #[Override]
    public function gc(int $max_lifetime): int|false
    {
        $pattern = $this->savePath . '/sess_*';
        $files = glob($pattern);

        if ($files === false) {
            return false;
        }

        $threshold = time() - $max_lifetime;
        $deleted = 0;

        foreach ($files as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime < $threshold) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    #[Override]
    public function supportsConcurrencyControl(): bool
    {
        return false;
    }

    #[Override]
    public function supportsSessionListing(): bool
    {
        return false;
    }

    #[Override]
    public function supportsRevocation(): bool
    {
        return false;
    }

    #[Override]
    public function listSessions(string $userId): array
    {
        throw SecurityException::sessionHandlerNotSupported('session listing', 'file');
    }

    #[Override]
    public function revokeSession(string $sessionId): bool
    {
        throw SecurityException::sessionHandlerNotSupported('session revocation', 'file');
    }

    #[Override]
    public function getActiveSessions(string $userId): int
    {
        throw SecurityException::sessionHandlerNotSupported('concurrency control', 'file');
    }

    private function sessionFile(string $id): string
    {
        return sprintf('%s/sess_%s', $this->savePath, $id);
    }
}
