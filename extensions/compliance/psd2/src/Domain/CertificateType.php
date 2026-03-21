<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use Pulsar\Api\Api;

/**
 * eIDAS certificate types used in PSD2 open banking (Art. 66-67).
 * @api
 */
#[Api(since: '1.0.0')]
enum CertificateType: string
{
    case Qwac = 'qwac';
    case Qseal = 'qseal';
}
