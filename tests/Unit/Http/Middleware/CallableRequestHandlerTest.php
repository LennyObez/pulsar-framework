<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\CallableRequestHandler;

#[CoversClass(CallableRequestHandler::class)]
final class CallableRequestHandlerTest extends TestCase
{
    #[Test]
    public function handleDelegatesToCallable(): void
    {
        $expected = new Response(statusCode: 201);
        $handler = new CallableRequestHandler(
            static fn(ServerRequestInterface $request): ResponseInterface => $expected,
        );

        $result = $handler->handle(new ServerRequest());

        self::assertSame($expected, $result);
    }

    #[Test]
    public function handlePassesRequestToCallable(): void
    {
        $receivedMethod = '';
        $handler = new CallableRequestHandler(
            static function (ServerRequestInterface $request) use (&$receivedMethod): ResponseInterface {
                $receivedMethod = $request->getMethod();
                return new Response();
            },
        );

        $handler->handle(new ServerRequest(method: 'DELETE'));

        self::assertSame('DELETE', $receivedMethod);
    }
}
