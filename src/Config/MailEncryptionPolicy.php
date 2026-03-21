<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Mail encryption policy for TLS/STARTTLS negotiation.
 * @api
 */
#[Api(since: '1.0.0')]
enum MailEncryptionPolicy: string
{
    case Require = 'require';
    case Prefer = 'prefer';
    case None = 'none';
}
