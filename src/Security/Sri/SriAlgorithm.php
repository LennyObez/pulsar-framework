<?php

declare(strict_types=1);

namespace Pulsar\Security\Sri;

use Pulsar\Api\Api;

/**
 * Supported hash algorithms for Subresource Integrity (SRI).
 *
 * Per W3C SRI spec, browsers support sha256, sha384, and sha512.
 * sha384 is recommended as the default; it balances security and
 * performance (faster than sha512 on most hardware, stronger than sha256).
 *
 * @see https://www.w3.org/TR/SRI/
 */
#[Api(since: '1.0.0')]
enum SriAlgorithm: string
{
    case Sha256 = 'sha256';
    case Sha384 = 'sha384';
    case Sha512 = 'sha512';
}
