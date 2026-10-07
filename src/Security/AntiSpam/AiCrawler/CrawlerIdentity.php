<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use Pulsar\Api\Internal;

/**
 * Outcome of verifying that a request claiming to be a known crawler really
 * originates from that crawler's operator.
 */
#[Internal]
enum CrawlerIdentity
{
    /** The client IP matches the crawler's published ranges or FCrDNS. */
    case Verified;

    /** The crawler is verifiable but the client failed every check — a forgery. */
    case Impersonator;

    /** No verification data is configured for this crawler; identity is unknown. */
    case Unverifiable;
}
