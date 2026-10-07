<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler\Internal;

use Override;
use Pulsar\Api\Internal;

use function array_column;
use function array_filter;
use function array_values;
use function dns_get_record;
use function gethostbyaddr;
use function gethostbynamel;
use function is_array;
use function is_string;

use const DNS_AAAA;

/**
 * {@see CrawlerDnsResolver} backed by the system resolver.
 *
 * Reverse via gethostbyaddr (PTR), forward via gethostbynamel (A) and
 * dns_get_record (AAAA). Lookups are blocking; failures yield an empty list.
 */
#[Internal]
final class SystemCrawlerDnsResolver implements CrawlerDnsResolver
{
    #[Override]
    public function reverse(string $ip): array
    {
        $host = @gethostbyaddr($ip);

        return is_string($host) && $host !== '' && $host !== $ip ? [$host] : [];
    }

    #[Override]
    public function forward(string $host): array
    {
        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = $ipv4;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            /** @var list<string> $v6 */
            $v6 = array_values(array_filter(array_column($aaaa, 'ipv6'), is_string(...)));
            $ips = [...$ips, ...$v6];
        }

        return $ips;
    }
}
