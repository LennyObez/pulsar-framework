<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

/**
 * EU MDR device risk classification.
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0745 (Annex VIII)
 */
#[Api(since: '1.0.0')]
enum DeviceRiskClass: string
{
    case ClassI = 'I';
    case ClassIIa = 'IIa';
    case ClassIIb = 'IIb';
    case ClassIII = 'III';
}
