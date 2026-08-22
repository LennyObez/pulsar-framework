<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use Pulsar\Api\Api;

/**
 * Types of cryptographic keys tracked by the key inventory.
 * @api
 */
#[Api(since: '1.0.0')]
enum KeyType: string
{
    /** Master application key for KDF derivation. */
    case Master = 'master';

    /** Encryption subkey derived from master. */
    case Encryption = 'encryption';

    /** HMAC/audit chain key. */
    case Audit = 'audit';

    /** TLS certificate private key. */
    case Tls = 'tls';

    /** Code/artifact signing key. */
    case Signing = 'signing';

    /** FIPS module validation certificate. */
    case FipsModule = 'fips_module';

    /** Tokenization key for PCI-DSS. */
    case Tokenization = 'tokenization';
}
