<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Dimensions available for audience segmentation.
 */
#[Api(since: '1.0.0')]
enum SegmentDimension: string
{
    case Country = 'country';
    case Browser = 'browser';
    case Os = 'os';
    case DeviceType = 'device_type';
    case ReferrerSource = 'referrer_source';
    case EntryPage = 'entry_page';
    case EventName = 'event_name';
    case PageVisited = 'page_visited';
    case VisitCount = 'visit_count';
    case UtmSource = 'utm_source';
    case UtmMedium = 'utm_medium';
    case UtmCampaign = 'utm_campaign';
}
