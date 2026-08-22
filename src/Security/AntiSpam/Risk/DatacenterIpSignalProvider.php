<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\TrustedProxy;
use Pulsar\Support\Net\CidrMatcher;

use function is_string;

/**
 * Datacenter-IP risk signal: scores requests whose real client IP falls within
 * operator-configured datacenter/hosting ranges.
 *
 * The client IP is resolved through trusted proxies so the check sees the actual
 * origin, not a reverse proxy. An empty range list or a non-matching IP
 * contributes no risk.
 */
#[Internal]
final readonly class DatacenterIpSignalProvider implements RiskSignalProviderInterface
{
    public function __construct(
        private DatacenterIpConfig $config,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    #[Override]
    public function evaluate(ServerRequestInterface $request): RiskSignal
    {
        if (!$this->config->enabled || $this->config->ranges === []) {
            return new RiskSignal(0.0, 'datacenter');
        }

        $ip = $this->clientIp($request);
        if ($ip === '') {
            return new RiskSignal(0.0, 'datacenter');
        }

        return CidrMatcher::matchesAny($ip, $this->config->ranges)
            ? new RiskSignal($this->config->score, 'datacenter')
            : new RiskSignal(0.0, 'datacenter');
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
