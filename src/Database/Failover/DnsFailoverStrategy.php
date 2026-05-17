<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Override;
use Pulsar\Api\Api;

use function dns_get_record;
use function gethostbyname;

use const DNS_A;

/**
 * Resolves a failover target by re-resolving a hostname via DNS.
 *
 * Uses DNS lookup to discover the current IP address of the given hostname,
 * which is useful when cloud/RDS endpoints update DNS records on failover.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DnsFailoverStrategy implements FailoverStrategyInterface
{
    public function __construct(
        private string $hostname,
    ) {}

    #[Override]
    public function resolveTarget(): ?string
    {
        $records = @dns_get_record($this->hostname, DNS_A);

        if ($records !== false && $records !== []) {
            /** @var string|null $ip */
            $ip = $records[0]['ip'] ?? null;

            return $ip;
        }

        $resolved = gethostbyname($this->hostname);

        if ($resolved !== $this->hostname) {
            return $resolved;
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'dns';
    }
}
