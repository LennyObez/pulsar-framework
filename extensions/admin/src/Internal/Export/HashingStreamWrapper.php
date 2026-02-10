<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Export;

use function hash_final;
use function hash_init;
use function hash_update;

use HashContext;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\WritableStreamInterface;

/**
 * Writable stream that computes a SHA-256 evidence hash of all written data.
 *
 * The hash is finalized on close() and can be retrieved via evidenceHash().
 */
#[Internal]
final class HashingStreamWrapper implements WritableStreamInterface
{
    private HashContext $hashContext;
    private string $buffer = '';
    public private(set) string $evidenceHash = '';
    public private(set) bool $closed = false;

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

}
