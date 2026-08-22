<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Http3\EarlyHintsInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\EarlyHintsMiddleware;

/**
 * The point of these tests is that the middleware is a real caller.
 *
 * EarlyHints existed for a full release cycle with a complete API and no production
 * code path reaching it, which is indistinguishable from a feature that works until
 * someone checks. What is asserted here is that a document navigation reaches send()
 * and that the request kinds which cannot act on a hint do not.
 */
#[CoversClass(EarlyHintsMiddleware::class)]
final class EarlyHintsMiddlewareTest extends TestCase
{
    #[Test]
    public function aDocumentNavigationIsHinted(): void
    {
        $hints = $this->recordingHints();
        $middleware = new EarlyHintsMiddleware($hints);

        $middleware->process(
            new ServerRequest(method: 'GET', uri: '/', headers: ['Sec-Fetch-Dest' => 'document']),
            $this->handler(),
        );

        self::assertSame(1, $hints->sent);
    }

    #[Test]
    public function aClientTooOldToDeclareItsDestinationIsStillHinted(): void
    {
        $hints = $this->recordingHints();

        new EarlyHintsMiddleware($hints)->process(
            new ServerRequest(method: 'GET', uri: '/'),
            $this->handler(),
        );

        self::assertSame(1, $hints->sent);
    }

    #[Test]
    public function anXhrCallIsNotHinted(): void
    {
        $hints = $this->recordingHints();

        new EarlyHintsMiddleware($hints)->process(
            new ServerRequest(method: 'GET', uri: '/api/things', headers: ['Sec-Fetch-Dest' => 'empty']),
            $this->handler(),
        );

        self::assertSame(0, $hints->sent, 'a fetch() renders no document, so it can preload nothing');
    }

    #[Test]
    public function aNonGetRequestIsNotHinted(): void
    {
        $hints = $this->recordingHints();

        new EarlyHintsMiddleware($hints)->process(
            new ServerRequest(method: 'POST', uri: '/orders', headers: ['Sec-Fetch-Dest' => 'document']),
            $this->handler(),
        );

        self::assertSame(0, $hints->sent);
    }

    #[Test]
    public function anEmptyHintSetSendsNothing(): void
    {
        $hints = $this->spy();

        new EarlyHintsMiddleware($hints)->process(
            new ServerRequest(method: 'GET', uri: '/', headers: ['Sec-Fetch-Dest' => 'document']),
            $this->handler(),
        );

        self::assertSame(0, $hints->sent, 'no hints registered means no reason to emit anything');
    }

    #[Test]
    public function theHandlerAlwaysRuns(): void
    {
        $response = new EarlyHintsMiddleware($this->recordingHints())->process(
            new ServerRequest(method: 'POST', uri: '/orders'),
            $this->handler(),
        );

        self::assertSame('handled', (string) $response->getBody());
    }

    /**
     * @return EarlyHintsInterface&object{sent: int}
     */
    private function recordingHints(): EarlyHintsInterface
    {
        return $this->spy(hasHints: true);
    }

    /**
     * @return EarlyHintsInterface&object{sent: int}
     */
    private function spy(bool $hasHints = false): EarlyHintsInterface
    {
        return new class ($hasHints) implements EarlyHintsInterface {
            public int $sent = 0;

            public function __construct(
                private readonly bool $hasHints,
            ) {}

            #[Override]
            public function send(): void
            {
                $this->sent++;
            }

            #[Override]
            public function isEmpty(): bool
            {
                return !$this->hasHints;
            }
        };
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('handled');
            }
        };
    }
}
