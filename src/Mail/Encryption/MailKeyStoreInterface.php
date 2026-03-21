<?php

declare(strict_types=1);

namespace Pulsar\Mail\Encryption;

use Pulsar\Api\Api;

/**
 * Manages recipient encryption key material (certificates or PGP public keys).
 * @api
 */
#[Api(since: '1.0.0')]
interface MailKeyStoreInterface
{
    /**
     * Retrieve the public key or certificate for a recipient email address.
     *
     * Returns a PEM-encoded X.509 certificate (for S/MIME) or an ASCII-armored
     * PGP public key, depending on the implementation.
     *
     * @param string $recipientEmail The recipient's email address
     *
     * @return string|null The key material, or null if none is stored
     */
    public function getPublicKey(string $recipientEmail): ?string;

    /**
     * Check whether a public key or certificate exists for a recipient.
     *
     * @param string $recipientEmail The recipient's email address
     */
    public function hasPublicKey(string $recipientEmail): bool;
}
