<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Encryption;

use Pulsar\Api\Internal;
use Pulsar\Database\Param;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;

/**
 * Generates blind index hashes for encrypted column lookups.
 *
 * Wraps the ColumnEncryptorInterface blind index method and produces
 * Param::binary() values ready for PDO binding.
 */
#[Internal]
final readonly class BlindIndexer
{
    public function __construct(
        private readonly ColumnEncryptorInterface $encryptor,
    ) {}

    /**
     * Compute a blind index Param for use in WHERE clauses.
     */
    public function compute(string $plaintext, int $hashLength = 32): Param
    {
        $hash = $this->encryptor->blindIndex($plaintext, $hashLength);

        return Param::binary($hash);
    }
}
