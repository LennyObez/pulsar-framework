<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\ManagedChallenge;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Http\Message\Response;

use function bin2hex;
use function intdiv;
use function is_int;
use function is_string;
use function sodium_crypto_generichash;
use function sprintf;
use function time;

/**
 * Mints a fresh signed managed challenge for the widget's silent refresh.
 *
 * The widget re-mints at ~80% of the challenge TTL so a slow human filling a
 * form for minutes is never rejected, while the server keeps a tight TTL (small
 * replay window). The endpoint is stateless, same-origin, carries no PII, and
 * needs no auth — it only issues PUBLIC, unsolved challenges. A best-effort
 * per-IP cache counter caps abuse (it costs a signature per call).
 */
#[Internal(reason: 'Managed challenge refresh endpoint; wired by AntiSpamWiring')]
final readonly class ManagedChallengeRefreshController
{
    /** Maximum refreshes per IP per minute. */
    private const int MAX_PER_MINUTE = 30;

    private const string CACHE_TAG = 'antispam_managed_challenge_refresh';

    public function __construct(
        private ManagedChallengeService $service,
        private ?TaggedCacheInterface $cache = null,
    ) {}

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->isRateLimited($request)) {
            return new Response(
                statusCode: 429,
                headers: ['Retry-After' => '60', 'Cache-Control' => 'no-store'],
                body: 'Rate limit exceeded',
            );
        }

        $challenge = $this->service->mint();
        $token = $this->service->sign($challenge);

        return Response::json([
            'challenge' => $token,
            'id' => $challenge->id,
            'bits' => $challenge->bits,
        ])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Best-effort per-IP, per-minute cap. Without a cache bound the endpoint
     * stays open (it only mints public challenges); the cap is defence in depth.
     */
    private function isRateLimited(ServerRequestInterface $request): bool
    {
        if ($this->cache === null) {
            return false;
        }

        $serverParams = $request->getServerParams();
        /** @var mixed $remoteAddr */
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? '';
        $ip = is_string($remoteAddr) ? $remoteAddr : '';

        $bucket = intdiv(time(), 60);
        $key = sprintf('antispam_mc_refresh.%s.%d', bin2hex(sodium_crypto_generichash($ip)), $bucket);

        /** @var mixed $current */
        $current = $this->cache->get($key);
        $count = is_int($current) ? $current : (is_string($current) ? (int) $current : 0);

        if ($count >= self::MAX_PER_MINUTE) {
            return true;
        }

        $this->cache->set($key, $count + 1, [self::CACHE_TAG], 60);

        return false;
    }
}
