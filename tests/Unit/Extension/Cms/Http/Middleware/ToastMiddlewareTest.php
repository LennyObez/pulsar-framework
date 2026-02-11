<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Http\Middleware\ToastMiddleware;
use Pulsar\Extension\Cms\Support\Toast;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Session\SessionInterface;

use function json_decode;
use function str_repeat;

#[CoversClass(ToastMiddleware::class)]
final class ToastMiddlewareTest extends TestCase
{
    #[Test]
    public function processReturnsResponseDirectlyWhenNoToasts(): void
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('has')->willReturn(false);

        $middleware = new ToastMiddleware($session);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('X-CMS-Toast'));
    }

    #[Test]
    public function processAddsToastHeaderFromSession(): void
    {
        $toast = Toast::success('Item saved');

        $session = $this->createStub(SessionInterface::class);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn([$toast]);

        $middleware = new ToastMiddleware($session);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        self::assertTrue($response->hasHeader('X-CMS-Toast'));

        /** @var list<array{message: string, type: string}> $decoded */
        $decoded = json_decode($response->getHeaderLine('X-CMS-Toast'), true);
        self::assertCount(1, $decoded);
        self::assertSame('Item saved', $decoded[0]['message']);
        self::assertSame('success', $decoded[0]['type']);
    }

    #[Test]
    public function processRemovesToastsFromSession(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn([Toast::success('Msg')]);
        $session->expects(self::once())->method('remove')->with('_toasts');

        $middleware = new ToastMiddleware($session);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $middleware->process($request, $handler);
    }

    #[Test]
    public function processFiltersNonToastItems(): void
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn([
            Toast::success('Valid'),
            'not-a-toast',
            42,
        ]);

        $middleware = new ToastMiddleware($session);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        /** @var list<array{message: string}> $decoded */
        $decoded = json_decode($response->getHeaderLine('X-CMS-Toast'), true);
        self::assertCount(1, $decoded);
        self::assertSame('Valid', $decoded[0]['message']);
    }

    #[Test]
    public function processCapsToastsAtTen(): void
    {
        $toasts = [];
        for ($i = 0; $i < 15; $i++) {
            $toasts[] = Toast::success("Message {$i}");
        }

        $session = $this->createStub(SessionInterface::class);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn($toasts);

        $middleware = new ToastMiddleware($session);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode($response->getHeaderLine('X-CMS-Toast'), true);
        self::assertCount(10, $decoded);
    }

    #[Test]
    public function processTruncatesLongMessages(): void
    {
        $longMessage = str_repeat('x', 600);
        $toast = Toast::success($longMessage);

        $session = $this->createStub(SessionInterface::class);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn([$toast]);

        $middleware = new ToastMiddleware($session);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        /** @var list<array{message: string}> $decoded */
        $decoded = json_decode($response->getHeaderLine('X-CMS-Toast'), true);
        self::assertSame(500, mb_strlen($decoded[0]['message']));
    }

    #[Test]
    public function processReturnsCleanResponseWhenOnlyInvalidItems(): void
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn(['not-a-toast', 42]);

        $middleware = new ToastMiddleware($session);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['ok' => true]));

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('X-CMS-Toast'));
    }
}
