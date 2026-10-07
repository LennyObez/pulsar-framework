<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal\Internal;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\NetworkIntelligenceInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;

use function is_string;

/**
 * Produces network-related trust claims.
 *
 * Classifies the request IP into network zones and checks against threat intelligence
 * for Tor exit nodes and known proxy servers.
 *
 * Claims produced:
 * - `network.zone` (string): Network zone classification (e.g., "internal", "external", "vpn")
 * - `network.tor_exit` (bool): Whether the IP is a known Tor exit node
 * - `network.known_proxy` (bool): Whether the IP is a known proxy
 */
#[Internal]
final readonly class NetworkSignalProvider implements SignalProviderInterface
{
    private const float CONFIDENCE_INTERNAL = 0.95;
    private const float CONFIDENCE_VERIFIED_PROXY = 0.85;
    private const float CONFIDENCE_EXTERNAL = 0.7;
    private const float CONFIDENCE_DEGRADED = 0.1;

    /** @var list<string> RFC 1918 / RFC 6598 private ranges in CIDR notation. */
    private const array PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '100.64.0.0/10',
    ];

    public function __construct(
        private ?NetworkIntelligenceInterface $networkIntelligence = null,
    ) {}

    public function evaluate(SignalContext $context): ClaimSet
    {
        $now = new DateTimeImmutable();
        $ip = $this->extractClientIp($context);

        if ($this->networkIntelligence === null) {
            return $this->degradedWithPrivateCheck($ip, $now);
        }

        $zone = $this->networkIntelligence->resolveZone($ip);
        $isTorExit = $this->networkIntelligence->isTorExitNode($ip);
        $isKnownProxy = $this->networkIntelligence->isKnownProxy($ip);

        $confidence = $this->resolveConfidence($zone, $isTorExit, $isKnownProxy);

        return new ClaimSet([
            new Claim(
                name: 'network.zone',
                value: $zone,
                source: ClaimSource::NetworkSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'network.tor_exit',
                value: $isTorExit,
                source: ClaimSource::NetworkSignal,
                confidence: $isTorExit ? self::CONFIDENCE_VERIFIED_PROXY : $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'network.known_proxy',
                value: $isKnownProxy,
                source: ClaimSource::NetworkSignal,
                confidence: $isKnownProxy ? self::CONFIDENCE_VERIFIED_PROXY : $confidence,
                timestamp: $now,
            ),
        ]);
    }

    public function name(): string
    {
        return 'network';
    }

    private function extractClientIp(SignalContext $context): string
    {
        $serverParams = $context->request->getServerParams();
        /** @var mixed $remoteAddr */
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? null;

        return is_string($remoteAddr) ? $remoteAddr : '127.0.0.1';
    }

    private function resolveConfidence(string $zone, bool $isTorExit, bool $isKnownProxy): float
    {
        if ($zone === 'internal') {
            return self::CONFIDENCE_INTERNAL;
        }

        if ($isTorExit || $isKnownProxy) {
            return self::CONFIDENCE_VERIFIED_PROXY;
        }

        return self::CONFIDENCE_EXTERNAL;
    }

    private function degradedWithPrivateCheck(string $ip, DateTimeImmutable $now): ClaimSet
    {
        $isPrivate = $this->isPrivateIp($ip);
        $zone = $isPrivate ? 'internal' : 'external';
        $confidence = $isPrivate ? self::CONFIDENCE_INTERNAL : self::CONFIDENCE_DEGRADED;

        return new ClaimSet([
            new Claim(
                name: 'network.zone',
                value: $zone,
                source: ClaimSource::NetworkSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'network.tor_exit',
                value: false,
                source: ClaimSource::NetworkSignal,
                confidence: self::CONFIDENCE_DEGRADED,
                timestamp: $now,
            ),
            new Claim(
                name: 'network.known_proxy',
                value: false,
                source: ClaimSource::NetworkSignal,
                confidence: self::CONFIDENCE_DEGRADED,
                timestamp: $now,
            ),
        ]);
    }

    private function isPrivateIp(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        return array_any(self::PRIVATE_RANGES, static function (string $range) use ($long): bool {
            [$subnet, $bits] = explode('/', $range);
            $subnetLong = ip2long($subnet);

            if ($subnetLong === false) {
                return false;
            }

            $mask = -1 << (32 - (int) $bits);

            return ($long & $mask) === ($subnetLong & $mask);
        });
    }
}
