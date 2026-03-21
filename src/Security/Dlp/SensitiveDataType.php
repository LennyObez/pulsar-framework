<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

/**
 * Classification of sensitive data types detected by the DLP engine.
 * @api
 */
#[Api(since: '1.0.0')]
enum SensitiveDataType: string
{
    /** Credit/debit card number (PCI-DSS). */
    case CreditCard = 'credit_card';

    /** Social Security Number. */
    case Ssn = 'ssn';

    /** API key or secret token. */
    case ApiKey = 'api_key';

    /** Email address in a context where it shouldn't appear. */
    case Email = 'email';

    /** IP address in user-facing response. */
    case IpAddress = 'ip_address';

    /** Electronic Protected Health Information (HIPAA). */
    case Ephi = 'ephi';

    /** Custom pattern registered by the application. */
    case Custom = 'custom';
}
