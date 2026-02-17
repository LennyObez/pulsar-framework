<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use Pulsar\Api\Api;

/**
 * Electronic signature formats recognized by eIDAS.
 *
 * Each format corresponds to a standard defined by ETSI for
 * creating qualified or advanced electronic signatures.
 */
#[Api(since: '1.0.0')]
enum SignatureFormat: string
{
    /** XML Advanced Electronic Signatures (ETSI EN 319 132). */
    case XAdES = 'xades';

    /** PDF Advanced Electronic Signatures (ETSI EN 319 142). */
    case PAdES = 'pades';

    /** CMS Advanced Electronic Signatures (ETSI EN 319 122). */
    case CAdES = 'cades';

    /** JSON Advanced Electronic Signatures (ETSI TS 119 182). */
    case JAdES = 'jades';
}
