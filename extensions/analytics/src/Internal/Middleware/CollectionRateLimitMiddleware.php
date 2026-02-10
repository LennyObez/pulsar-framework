<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\CacheDriverInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Http\Message\Response;

use function in_array;

/**
 * Rate limits the analytics collection endpoint by client IP.
 *
 * Trusts X-Forwarded-For only when the direct connection comes from a
 * configured trusted proxy. Without this, attackers can spoof their IP.
 */
#[Internal(reason: 'Analytics middleware — rate limiting')]
final readonly class CollectionRateLimitMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $trustedProxies;

    public function __construct(
        private AnalyticsConfig $config,
        private ?CacheDriverInterface $cache,
    ) {
        $this->trustedProxies = $config->trustedProxies;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->cache === null) {
            return $handler->handle($request);
        }

        $ip = $this->getClientIp($request);
        $key = 'analytics:rate:' . md5($ip);
        $maxPerMinute = $this->config->rateLimit->maxEventsPerIpPerMinute + $this->config->rateLimit->burst;

        $current = (int) $this->cache->get($key);

        if ($current >= $maxPerMinute) {
            return Response::noContent();
        }

        $this->cache->increment($key);

        if ($current === 0) {
            $this->cache->expire($key, 60);
        }

        return $handler->handle($request);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');

        if ($forwardedFor !== '' && in_array($remoteAddr, $this->trustedProxies, true)) {
            $ips = explode(',', $forwardedFor);

            return trim($ips[0]);
        }

        return $remoteAddr;
    }
}
