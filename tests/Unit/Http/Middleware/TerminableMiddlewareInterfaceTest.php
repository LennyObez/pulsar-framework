<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Middleware\TerminableMiddlewareInterface;
use ReflectionClass;

#[CoversNothing]
final class TerminableMiddlewareInterfaceTest extends TestCase
{
    #[Test]
    public function terminableMiddlewareCanBeImplemented(): void
    {
        $terminated = false;

        $middleware = new class ($terminated) implements TerminableMiddlewareInterface {
            /** @phpstan-ignore property.onlyWritten */
            public function __construct(private bool &$terminated) {}

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request);
            }

            public function terminate(
                ServerRequestInterface $request,
                ResponseInterface $response,
            ): void {
                $this->terminated = true;
            }
        };

        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $middleware->terminate($request, $response);

        self::assertTrue($terminated);
    }

    #[Test]
    public function terminableMiddlewareExtendsPsrMiddleware(): void
    {
        $reflection = new ReflectionClass(TerminableMiddlewareInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue(
            $reflection->isSubclassOf(\Psr\Http\Server\MiddlewareInterface::class),
        );
    }
}
