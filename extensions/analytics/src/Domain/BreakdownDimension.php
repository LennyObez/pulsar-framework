<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Dimensions available for stats breakdown queries.
 */
#[Api(since: '1.0.0')]
enum BreakdownDimension: string
{
    case Page = 'page';
    case Referrer = 'referrer';
    case Country = 'country';
    case Browser = 'browser';
    case Os = 'os';
    case Device = 'device';
    case UtmSource = 'utm_source';
    case UtmMedium = 'utm_medium';
    case UtmCampaign = 'utm_campaign';
}
