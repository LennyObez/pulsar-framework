<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use Random\RandomException;
use SensitiveParameter;
use SodiumException;

#[Api(since: '1.0.0')]
interface EncryptorInterface
{
    /**
     * Implementations must repeat the `#[SensitiveParameter]` marker: the attribute
     * is read from the frame on the stack, not from the interface.
     *
     * @throws SecurityException
     * @throws RandomException
     * @throws SodiumException
     */
    public function encrypt(
        #[SensitiveParameter]
        string $plaintext,
    ): string;

    /**
     * @throws SecurityException
     * @throws SodiumException
     */
    public function decrypt(string $encoded): string;

    /**
     * Create an encryptor using a specific subkey derivation.
     *
     * Enables domain separation for subsystems that need their own
     * encryption keys (e.g., Studio uses subKeyId=3, context='stud_enc').
     *
     * @throws SodiumException
     */
    public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self;
}
