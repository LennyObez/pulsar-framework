<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

/**
 * UDI issuing agencies recognized by the EU MDR.
 *
 * @see https://health.ec.europa.eu/medical-devices-sector/new-regulations/udi_en
 */
#[Api(since: '1.0.0')]
enum UdiIssuingAgency: string
{
    case GS1 = 'gs1';
    case HIBCC = 'hibcc';
    case ICCBBA = 'iccbba';
    case IFA = 'ifa';
}
