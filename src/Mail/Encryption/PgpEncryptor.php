<?php

declare(strict_types=1);

namespace Pulsar\Mail\Encryption;

use Override;
use Pulsar\Api\Api;
use Pulsar\Mail\Exception\MailException;

use function call_user_func;
use function extension_loaded;
use function is_array;
use function is_string;

/**
 * PGP message body encryptor using the GnuPG extension.
 *
 * Uses dynamic function calls via {@see call_user_func()} to avoid compile-time
 * dependency on the gnupg extension, which may not be installed in all environments.
 */
#[Api(since: '1.0.0')]
final readonly class PgpEncryptor implements MailEncryptorInterface
{
    public function __construct()
    {
        if (!extension_loaded('gnupg')) {
            throw MailException::sendFailed(
                'PGP encryption requires the gnupg PHP extension',
            );
        }
    }

    #[Override]
    public function encrypt(string $body, string $recipientKeyMaterial): string
    {
        /** @var resource|false $gpg */
        $gpg = call_user_func('gnupg_init');

        if ($gpg === false) {
            throw MailException::sendFailed('Failed to initialize GnuPG context');
        }

        /** @var array{fingerprint?: string}|false $importResult */
        $importResult = call_user_func('gnupg_import', $gpg, $recipientKeyMaterial);

        if (!is_array($importResult) || !isset($importResult['fingerprint'])) {
            throw MailException::sendFailed('Failed to import PGP public key: verify the key is valid ASCII-armored format');
        }

        $fingerprint = $importResult['fingerprint'];

        /** @var bool $addKeyResult */
        $addKeyResult = call_user_func('gnupg_addencryptkey', $gpg, $fingerprint);

        if ($addKeyResult === false) {
            throw MailException::sendFailed('Failed to add PGP encryption key for fingerprint: ' . $fingerprint);
        }

        /** @var string|false $encrypted */
        $encrypted = call_user_func('gnupg_encrypt', $gpg, $body);

        if (!is_string($encrypted) || $encrypted === '') {
            throw MailException::sendFailed('PGP encryption failed: gnupg_encrypt returned no data');
        }

        return $encrypted;
    }

    #[Override]
    public function type(): EncryptionType
    {
        return EncryptionType::Pgp;
    }
}
