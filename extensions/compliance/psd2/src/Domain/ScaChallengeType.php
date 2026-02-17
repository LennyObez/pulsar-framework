<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use Pulsar\Api\Api;

/**
 * SCA challenge method types per PSD2 Art. 97.
 */
#[Api(since: '1.0.0')]
enum ScaChallengeType: string
{
    case Totp = 'totp';
    case Push = 'push';
    case Sms = 'sms';
    case Biometric = 'biometric';
}
