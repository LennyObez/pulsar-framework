<?php

declare(strict_types=1);

namespace Pulsar\Mail\Encryption;

use Override;
use Pulsar\Api\Api;
use Pulsar\Mail\Exception\MailException;

use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function is_string;
use function openssl_pkcs7_encrypt;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const OPENSSL_CIPHER_AES_256_CBC;
use const PKCS7_BINARY;

/**
 * S/MIME message body encryptor using OpenSSL PKCS#7.
 *
 * Encrypts content with AES-256-CBC using the recipient's X.509 certificate.
 * Since this deals with X.509 certificates (not symmetric/Keyring crypto),
 * direct OpenSSL usage is acceptable.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SmimeEncryptor implements MailEncryptorInterface
{
    public function __construct()
    {
        if (!function_exists('openssl_pkcs7_encrypt')) {
            throw MailException::sendFailed(
                'S/MIME encryption requires the OpenSSL extension (openssl_pkcs7_encrypt)',
            );
        }
    }

    #[Override]
    public function encrypt(string $body, string $recipientKeyMaterial): string
    {
        $inputFile = tempnam(sys_get_temp_dir(), 'pulsar_smime_in_');
        $outputFile = tempnam(sys_get_temp_dir(), 'pulsar_smime_out_');

        if ($inputFile === false || $outputFile === false) {
            throw MailException::sendFailed('Failed to create temporary files for S/MIME encryption');
        }

        try {
            if (file_put_contents($inputFile, $body) === false) {
                throw MailException::sendFailed('Failed to write plaintext to temp file for S/MIME encryption');
            }

            $result = openssl_pkcs7_encrypt(
                $inputFile,
                $outputFile,
                $recipientKeyMaterial,
                [],
                PKCS7_BINARY,
                OPENSSL_CIPHER_AES_256_CBC,
            );

            if ($result === false) {
                throw MailException::sendFailed('S/MIME encryption failed: verify the recipient certificate is valid');
            }

            $encrypted = file_get_contents($outputFile);

            if (!is_string($encrypted) || $encrypted === '') {
                throw MailException::sendFailed('S/MIME encryption produced empty output');
            }

            return $encrypted;
        } finally {
            unlink($inputFile);
            unlink($outputFile);
        }
    }

    #[Override]
    public function type(): EncryptionType
    {
        return EncryptionType::Smime;
    }
}
