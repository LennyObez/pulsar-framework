<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Http\Middleware\CommentHoneypotMiddleware;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CommentHoneypotMiddleware::class)]
final class CommentHoneypotMiddlewareTest extends TestCase
{
    #[Test]
    public function processPassesThroughWhenHoneypotEmpty(): void
    {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $middleware = new CommentHoneypotMiddleware($auditLogger);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'Nice post!', 'website_url' => '']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processPassesThroughWhenHoneypotMissing(): void
    {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $middleware = new CommentHoneypotMiddleware($auditLogger);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'Nice post!']);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processReturnsFakeSuccessWhenHoneypotFilled(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $middleware = new CommentHoneypotMiddleware($auditLogger);

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'Buy now!', 'website_url' => 'http://spam.com']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        // Returns fake 200 success to not reveal detection
        self::assertSame(200, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('success', $body);
    }

    #[Test]
    public function processPassesThroughWhenBodyIsNotArray(): void
    {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $middleware = new CommentHoneypotMiddleware($auditLogger);

        $request = new ServerRequest(method: 'GET', uri: '/comments');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function processUsesCustomHoneypotFieldName(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $middleware = new CommentHoneypotMiddleware($auditLogger, honeypotField: 'fax_number');

        $request = new ServerRequest(method: 'POST', uri: '/comments')
            ->withParsedBody(['body' => 'Spam', 'fax_number' => '555-1234']);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $result = $middleware->process($request, $handler);

        self::assertSame(200, $result->getStatusCode());
    }
}
