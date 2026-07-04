<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\AiCrawler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\SlidingWindowRateLimiter;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerDetector;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerMiddleware;

#[CoversClass(AiCrawlerMiddleware::class)]
final class AiCrawlerMiddlewareTest extends TestCase
{
    #[Test]
    public function blocksTrainingCrawlerByDefault(): void
    {
        $response = $this->middleware([])->process($this->request('GPTBot/1.0'), $this->okHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('noai', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function allowsAssistantCrawlerByDefault(): void
    {
        $response = $this->middleware([])->process($this->request('ChatGPT-User/1.0'), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('noai', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function stampsTdmReservationOnNonCrawlerRequests(): void
    {
        $response = $this->middleware([])->process($this->request('Mozilla/5.0'), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('noai', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function tdmReservationCanBeDisabled(): void
    {
        $response = $this->middleware(['send_tdm_reservation' => false])->process($this->request('Mozilla/5.0'), $this->okHandler());

        self::assertSame('', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function perCrawlerOverrideWinsOverCategoryDefault(): void
    {
        $response = $this->middleware(['overrides' => ['GPTBot' => 'allow']])->process($this->request('GPTBot/1.0'), $this->okHandler());

        self::assertSame(200, $response->getStatusCode(), 'override allows the otherwise-blocked training crawler');
    }

    #[Test]
    public function throttlesWhenActionIsRateLimit(): void
    {
        $config = AiCrawlerConfig::fromArray(['training_action' => 'rate_limit']);
        $middleware = new AiCrawlerMiddleware($config, new AiCrawlerDetector($config), new SlidingWindowRateLimiter(1, 60));

        $first = $middleware->process($this->request('GPTBot/1.0'), $this->okHandler());
        $second = $middleware->process($this->request('GPTBot/1.0'), $this->okHandler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertNotSame('', $second->getHeaderLine('Retry-After'));
    }

    /**
     * @param array<string, mixed> $configData
     */
    private function middleware(array $configData): AiCrawlerMiddleware
    {
        $config = AiCrawlerConfig::fromArray($configData);

        return new AiCrawlerMiddleware($config, new AiCrawlerDetector($config), $this->limiter());
    }

    private function limiter(): RateLimiterInterface
    {
        return new SlidingWindowRateLimiter(1000, 60);
    }

    private function request(string $userAgent): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/', headers: ['User-Agent' => $userAgent]);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('OK');
            }
        };
    }
}
