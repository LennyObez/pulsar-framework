<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;

/**
 * GDPR-aligned legal bases for processing personal data in notifications.
 */
#[Api(since: '1.0.0')]
enum LegalBasis: string
{
    case Consent = 'consent';
    case LegitimateInterest = 'legitimate_interest';
    case LegalObligation = 'legal_obligation';
    case VitalInterest = 'vital_interest';
    case PublicTask = 'public_task';
    case Contract = 'contract';
}
