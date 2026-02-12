<?php

declare(strict_types=1);

namespace Pulsar\Mail\Encryption;

use Pulsar\Api\Api;

/**
 * Supported mail content encryption types (S/MIME, PGP).
 *
 * This covers message-body encryption — distinct from TLS transport encryption
 * configured via {@see \Pulsar\Config\MailEncryptionPolicy}.
 */
#[Api(since: '1.0.0')]
enum EncryptionType: string
{
    case Smime = 'smime';
    case Pgp = 'pgp';
}
