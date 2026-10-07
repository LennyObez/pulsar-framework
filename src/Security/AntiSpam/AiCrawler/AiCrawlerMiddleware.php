<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\AntiSpam\AiCrawler\Internal\CrawlerIdentityVerifier;

use function is_string;

/**
 * Global middleware that enforces the configured AI-crawler policy.
 *
 * Detected AI crawlers are allowed, blocked (403), or throttled (429) per
 * their category and any per-crawler override. A TDM-reservation header
 * (X-Robots-Tag: noai, noimageai) is added to every response so that even
 * allowed crawlers — and crawlers that ignore robots.txt — are told the
 * content is not licensed for AI training/use.
 *
 * When an identity verifier is wired, a request whose User-Agent claims a known
 * crawler but whose IP fails that crawler's published ranges / reverse DNS is
 * treated as an impersonator and blocked (403) regardless of the crawler's
 * configured action — closing the trivial "forge an allowed crawler's UA" bypass.
 */
#[Internal]
final readonly class AiCrawlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AiCrawlerConfig $config,
        private AiCrawlerDetector $detector,
        private RateLimiterInterface $rateLimiter,
        private ?LoggerInterface $logger = null,
        private ?CrawlerIdentityVerifier $identityVerifier = null,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $detected = $this->detector->detect($request);

        if ($detected === null) {
            return $this->withTdmReservation($handler->handle($request));
        }

        if ($this->identityVerifier !== null
            && $this->identityVerifier->verify($detected->token, $this->verificationIp($request)) === CrawlerIdentity::Impersonator
        ) {
            return $this->blockImpersonator($detected);
        }

        return match ($this->config->resolveAction($detected->token, $detected->category)) {
            AiCrawlerAction::Block => $this->block($detected),
            AiCrawlerAction::RateLimit => $this->rateLimit($detected, $request, $handler),
            AiCrawlerAction::Allow => $this->withTdmReservation($handler->handle($request)),
        };
    }

    private function block(DetectedAiCrawler $crawler): ResponseInterface
    {
        $this->logger?->info('AI crawler blocked', [
            'crawler' => $crawler->token,
            'category' => $crawler->category->value,
        ]);

        return $this->withTdmReservation(Response::json(
            ['error' => 'AI crawler access is not permitted'],
            ResponseStatus::Forbidden->value,
        ));
    }

    private function blockImpersonator(DetectedAiCrawler $crawler): ResponseInterface
    {
        $this->logger?->warning('AI crawler impersonation blocked', [
            'crawler' => $crawler->token,
            'category' => $crawler->category->value,
        ]);

        return $this->withTdmReservation(Response::json(
            ['error' => 'Crawler identity could not be verified'],
            ResponseStatus::Forbidden->value,
        ));
    }

    private function rateLimit(
        DetectedAiCrawler $crawler,
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $key = "ai-crawler:{$crawler->token}:" . $this->clientIp($request);
        $result = $this->rateLimiter->hit($key);

        if ($result->exceeded()) {
            $this->logger?->info('AI crawler throttled', [
                'crawler' => $crawler->token,
                'category' => $crawler->category->value,
            ]);

            return $this->withTdmReservation(
                Response::json(
                    ['error' => 'Rate limit exceeded', 'retry_after' => $result->retryAfter],
                    ResponseStatus::TooManyRequests->value,
                )->withHeader('Retry-After', (string) $result->retryAfter),
            );
        }

        return $this->withTdmReservation($handler->handle($request));
    }

    private function withTdmReservation(ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->sendTdmReservation) {
            return $response;
        }

        return $response->withAddedHeader('X-Robots-Tag', 'noai, noimageai');
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }

    /**
     * The real client IP for identity verification: behind a trusted proxy the
     * crawler's address is in X-Forwarded-For, not REMOTE_ADDR.
     */
    private function verificationIp(ServerRequestInterface $request): string
    {
        return $this->trustedProxy?->resolveClientIp($request) ?? $this->clientIp($request);
    }
}
