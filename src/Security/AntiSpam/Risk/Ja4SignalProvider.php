<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\TrustedProxy;

use function array_any;
use function in_array;
use function is_string;
use function str_starts_with;

/**
 * Risk signal from a JA4/JA4+ TLS fingerprint supplied by the edge.
 *
 * Reads the fingerprint from the configured header (populated by the
 * TLS-terminating proxy) and scores a match against the operator's known-bad
 * list. The header is honoured only when the request arrives through a trusted
 * proxy (unless that gate is disabled), so a client connecting directly cannot
 * spoof a benign fingerprint. An absent or unknown fingerprint contributes no
 * risk — this is a denylist signal, not an allowlist.
 */
#[Internal]
final readonly class Ja4SignalProvider implements RiskSignalProviderInterface
{
    public function __construct(
        private Ja4Config $config,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    #[Override]
    public function evaluate(ServerRequestInterface $request): RiskSignal
    {
        if ($this->config->trustedProxiesOnly && !$this->fromTrustedProxy($request)) {
            return new RiskSignal(0.0, 'ja4');
        }

        $fingerprint = $request->getHeaderLine($this->config->headerName);

        if ($fingerprint === '') {
            return new RiskSignal(0.0, 'ja4');
        }

        // An explicit allowlist entry is always safe and overrides the broader
        // prefix denylist (so a flagged family can still permit known-good members).
        if (in_array($fingerprint, $this->config->knownGoodFingerprints, true)) {
            return new RiskSignal(0.0, 'ja4');
        }

        if (in_array($fingerprint, $this->config->knownBadFingerprints, true)) {
            return new RiskSignal($this->config->matchScore, 'ja4');
        }

        // Family match: a JA4 prefix (typically the JA4_a component) catches a
        // fingerprint family even as the later components vary. Lower-confidence
        // than an exact match, so it scores partialMatchScore.
        if ($this->matchesBadPrefix($fingerprint)) {
            return new RiskSignal($this->config->partialMatchScore, 'ja4');
        }

        return new RiskSignal(0.0, 'ja4');
    }

    private function matchesBadPrefix(string $fingerprint): bool
    {
        return array_any(
            $this->config->knownBadPrefixes,
            static fn(string $prefix): bool => $prefix !== '' && str_starts_with($fingerprint, $prefix),
        );
    }

    private function fromTrustedProxy(ServerRequestInterface $request): bool
    {
        if ($this->trustedProxy === null) {
            return false;
        }

        /** @var mixed $remoteAddr */
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remoteAddr) && $this->trustedProxy->isTrustedSource($remoteAddr);
    }
}
