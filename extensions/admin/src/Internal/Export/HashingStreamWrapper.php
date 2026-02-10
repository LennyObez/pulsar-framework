<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Export;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\WritableStreamInterface;

use function hash_final;
use function hash_init;
use function hash_update;

/**
 * Writable stream that computes a SHA-256 evidence hash of all written data.
 *
 * The hash is finalized on close() and can be retrieved via evidenceHash().
 */
#[Internal]
final class HashingStreamWrapper implements WritableStreamInterface
{
    private \HashContext $hashContext;
    private string $buffer = '';
    private string $evidenceHash = '';
    private bool $closed = false;

    public function __construct()
    {
        $this->hashContext = hash_init('sha256');
    }

    #[Override]
    public function write(string $data): void
    {
        $this->buffer .= $data;
        hash_update($this->hashContext, $data);
    }

    #[Override]
    public function contents(): string
    {
        return $this->buffer;
    }

    #[Override]
    public function close(): void
    {
        if (!$this->closed) {
            $this->evidenceHash = hash_final($this->hashContext);
            $this->closed = true;
        }
    }

    /**
     * Get the SHA-256 evidence hash. Only available after close().
     */
    public function evidenceHash(): string
    {
        return $this->evidenceHash;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
