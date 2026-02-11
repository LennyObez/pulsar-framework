<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\ValidationMiddleware;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\ValidationException;

#[CoversClass(ValidationMiddleware::class)]
final class ValidationMiddlewareTest extends TestCase
{
    #[Test]
    public function processPassesWhenValidationSucceeds(): void
    {
        $middleware = new class extends ValidationMiddleware {
            /** @return array<string, list<RuleInterface>> */
            protected function rules(ServerRequestInterface $request): array
            {
                return [
                    'name' => [new Required()],
                ];
            }
        };

        $request = new ServerRequest(
            queryParams: ['name' => 'Alice'],
        );

        $expectedResponse = new Response(statusCode: 200);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $result = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $result);
    }

    #[Test]
    public function processThrowsWhenValidationFails(): void
    {
        $middleware = new class extends ValidationMiddleware {
            /** @return array<string, list<RuleInterface>> */
            protected function rules(ServerRequestInterface $request): array
            {
                return [
                    'email' => [new Required()],
                ];
            }
        };

        $request = new ServerRequest(queryParams: []);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $this->expectException(ValidationException::class);
        $middleware->process($request, $handler);
    }

    #[Test]
    public function processMergesQueryAndParsedBody(): void
    {
        $middleware = new class extends ValidationMiddleware {
            /** @return array<string, list<RuleInterface>> */
            protected function rules(ServerRequestInterface $request): array
            {
                return [
                    'name' => [new Required()],
                    'page' => [new Required()],
                ];
            }
        };

        $request = new ServerRequest(
            queryParams: ['page' => '1'],
            parsedBody: ['name' => 'Bob'],
        );

        $expectedResponse = new Response();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $result = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $result);
    }

    #[Test]
    public function processHandlesNonArrayParsedBody(): void
    {
        $middleware = new class extends ValidationMiddleware {
            /** @return array<string, list<RuleInterface>> */
            protected function rules(ServerRequestInterface $request): array
            {
                return [
                    'q' => [new Required()],
                ];
            }
        };

        $request = new ServerRequest(
            queryParams: ['q' => 'search'],
            parsedBody: null,
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }
}
