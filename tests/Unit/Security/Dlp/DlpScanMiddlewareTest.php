<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\DlpMatch;
use Pulsar\Security\Dlp\DlpScanMiddleware;
use Pulsar\Security\Dlp\DlpScanResult;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePatternRegistry;

#[CoversClass(DlpScanMiddleware::class)]
#[CoversClass(DlpScanResult::class)]
#[CoversClass(DlpMatch::class)]
final class DlpScanMiddlewareTest extends TestCase
{
    private function createRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/data');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }

    private function createResponseWithBody(string $body): ResponseInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function createRealRegistry(bool $enabled = true): SensitivePatternRegistry
    {
        return new SensitivePatternRegistry(new DlpConfig(enabled: $enabled));
    }

    public function testDisabledConfigPassesThrough(): void
    {
        $config = new DlpConfig(enabled: false);
        $registry = $this->createRealRegistry(false);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $expectedResponse = $this->createResponseWithBody('Credit card: 4111111111111111');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $middleware = new DlpScanMiddleware($registry, $config, $auditLogger);
        $result = $middleware->process($this->createRequest(), $handler);

        self::assertSame($expectedResponse, $result);
    }

    public function testScanResponsesDisabledPassesThrough(): void
    {
        $config = new DlpConfig(enabled: true, scanResponses: false);
        $registry = $this->createRealRegistry();
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $expectedResponse = $this->createResponseWithBody('sensitive data');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $middleware = new DlpScanMiddleware($registry, $config, $auditLogger);
        $result = $middleware->process($this->createRequest(), $handler);

        self::assertSame($expectedResponse, $result);
    }

    public function testEmptyBodyPassesThrough(): void
    {
        $config = new DlpConfig(enabled: true);
        $registry = $this->createRealRegistry();
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $expectedResponse = $this->createResponseWithBody('');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $middleware = new DlpScanMiddleware($registry, $config, $auditLogger);
        $result = $middleware->process($this->createRequest(), $handler);

        self::assertSame($expectedResponse, $result);
    }

    public function testCleanBodyPassesThrough(): void
    {
        $config = new DlpConfig(enabled: true);
        $registry = $this->createRealRegistry();
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $expectedResponse = $this->createResponseWithBody('This is a clean response without sensitive data.');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $middleware = new DlpScanMiddleware($registry, $config, $auditLogger);
        $result = $middleware->process($this->createRequest(), $handler);

        self::assertSame($expectedResponse, $result);
    }

    public function testBlockActionReturnsError(): void
    {
        $config = new DlpConfig(enabled: true, defaultAction: DlpAction::Block);
        $registry = $this->createRealRegistry();

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        // Use a real credit card test number that the scanner will detect
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createResponseWithBody('Payment card: 4111111111111111 confirmed'));

        $middleware = new DlpScanMiddleware($registry, $config, $auditLogger);
        $result = $middleware->process($this->createRequest(), $handler);

        $body = (string) $result->getBody();
        self::assertStringContainsString('data loss prevention', $body);
    }

    public function testAlertActionPassesThroughWithAudit(): void
    {
        $config = new DlpConfig(enabled: true, defaultAction: DlpAction::Alert);
        $registry = $this->createRealRegistry();

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        // SSN pattern that will trigger detection
        $originalResponse = $this->createResponseWithBody('SSN: 123-45-6789 on file');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($originalResponse);

        $middleware = new DlpScanMiddleware($registry, $config, $auditLogger);
        $result = $middleware->process($this->createRequest(), $handler);

        // Alert action passes through the original response
        self::assertSame($originalResponse, $result);
    }

    public function testDlpScanResultClean(): void
    {
        $result = DlpScanResult::clean('hello');

        self::assertFalse($result->detected);
        self::assertSame(DlpAction::Alert, $result->actionTaken);
        self::assertSame([], $result->matches);
        self::assertSame('hello', $result->redactedContent);
    }

    public function testDlpMatchProperties(): void
    {
        $match = new DlpMatch(
            type: SensitiveDataType::Ssn,
            pattern: '/\d{3}-\d{2}-\d{4}/',
            offset: 10,
            length: 11,
            maskedValue: '***-**-6789',
        );

        self::assertSame(SensitiveDataType::Ssn, $match->type);
        self::assertSame('/\d{3}-\d{2}-\d{4}/', $match->pattern);
        self::assertSame(10, $match->offset);
        self::assertSame(11, $match->length);
        self::assertSame('***-**-6789', $match->maskedValue);
    }
}
