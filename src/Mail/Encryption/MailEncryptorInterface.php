<?php

declare(strict_types=1);

namespace Pulsar\Mail\Encryption;

use Pulsar\Api\Api;
use Pulsar\Mail\Exception\MailException;

/**
 * Encrypts mail body content using a recipient's public key material.
 */
#[Api(since: '1.0.0')]
interface MailEncryptorInterface
{
    /**
     * Encrypt the message body using the recipient's public key or certificate.
     *
     * @param string $body                The raw message body to encrypt
     * @param string $recipientKeyMaterial PEM-encoded certificate (S/MIME) or ASCII-armored PGP public key
     *
     * @return string The encrypted body
     *
     * @throws MailException If encryption fails
     */
    public function encrypt(string $body, string $recipientKeyMaterial): string;

    /**
     * Get the encryption type this encryptor handles.
     */
    public function type(): EncryptionType;
}
