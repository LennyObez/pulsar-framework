<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Vigilance;

use Pulsar\Api\Api;

/**
 * Classification of serious incidents per MDR Article 2(65).
 * @api
 */
#[Api(since: '1.0.0')]
enum SeriousIncidentType: string
{
    case Death = 'death';
    case SeriousDeteriorationOfHealth = 'serious_deterioration_of_health';
    case PublicHealthThreat = 'public_health_threat';
    case Other = 'other';
}
