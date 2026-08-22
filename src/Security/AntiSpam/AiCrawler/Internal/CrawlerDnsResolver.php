<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler\Internal;

use Pulsar\Api\Internal;

/**
 * DNS lookups for forward-confirmed reverse DNS (FCrDNS) crawler verification.
 *
 * Abstracted so the verifier can be tested without real DNS and so the blocking
 * lookups can be swapped for an async/cached resolver in demanding deployments.
 */
#[Internal]
interface CrawlerDnsResolver
{
    /**
     * Reverse (PTR) lookup: the host name(s) an IP resolves to.
     *
     * @return list<string>
     */
    public function reverse(string $ip): array;

    /**
     * Forward lookup: the IP address(es) a host name resolves to (A + AAAA).
     *
     * @return list<string>
     */
    public function forward(string $host): array;
}
