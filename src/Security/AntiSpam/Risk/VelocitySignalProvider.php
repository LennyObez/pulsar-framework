<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Closure;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Http\TrustedProxy;

use function hash;
use function intdiv;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function time;

/**
 * Request-velocity risk signal: scores a client by how fast it is hitting the
 * origin within a fixed window.
 *
 * Below the threshold the signal is zero; above it the score grows with the
 * overage, capped at {@see VelocityConfig::$maxScore}. The per-client counter
 * is keyed on the real client IP (resolved through trusted proxies) and stored
 * in the cache. The clock is injectable so the windowing is deterministic in
 * tests.
 */
#[Internal]
final readonly class VelocitySignalProvider implements RiskSignalProviderInterface
{
    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private VelocityConfig $config,
        private TaggedCacheInterface $cache,
        private ?TrustedProxy $trustedProxy = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    #[Override]
    public function evaluate(ServerRequestInterface $request): RiskSignal
    {
        if (!$this->config->enabled) {
            return new RiskSignal(0.0, 'velocity');
        }

        $ip = $this->clientIp($request);
        if ($ip === '') {
            return new RiskSignal(0.0, 'velocity');
        }

        $window = max(1, $this->config->windowSeconds);
        $bucket = intdiv(($this->clock)(), $window);
        $key = 'risk.velocity.' . hash('sha256', $ip) . '.' . $bucket;

        $count = $this->currentCount($key) + 1;
        $this->cache->set($key, (string) $count, ['risk-velocity'], $window);

        $threshold = max(1, $this->config->threshold);
        if ($count <= $threshold) {
            return new RiskSignal(0.0, 'velocity');
        }

        $overage = ($count - $threshold) / $threshold;

        return new RiskSignal(min($this->config->maxScore, $this->config->maxScore * $overage), 'velocity');
    }

    private function currentCount(string $key): int
    {
        /** @var mixed $value */
        $value = $this->cache->get($key);

        return is_numeric($value) ? (int) $value : 0;
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
