<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ApiSigning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\ApiSigning\RequestSignatureMiddleware;
use Pulsar\Security\ApiSigning\RequestSigner;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatEventDispatcherInterface;

use function str_repeat;

#[CoversClass(RequestSignatureMiddleware::class)]
final class RequestSignatureMiddlewareTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = str_repeat('k', 32);
    }

    private function createHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }

    #[Test]
    public function valid_signature_passes_through(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');
        $middleware = new RequestSignatureMiddleware($signer);

        $signed = $signer->sign(new ServerRequest(method: 'GET', uri: '/api/data'));
        $response = $middleware->process($signed, $this->createHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', (string) $response->getBody());
    }

    #[Test]
    public function missing_signature_returns_401(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');
        $middleware = new RequestSignatureMiddleware($signer);

        $request = new ServerRequest(method: 'GET', uri: '/api/data');
        $response = $middleware->process($request, $this->createHandler());

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function tampered_request_returns_401(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');
        $middleware = new RequestSignatureMiddleware($signer);

        $signed = $signer->sign(new ServerRequest(method: 'POST', uri: '/api', body: 'original'));

        $tampered = new ServerRequest(
            method: 'POST',
            uri: '/api',
            headers: [
                RequestSigner::HEADER_SIGNATURE => $signed->getHeaderLine(RequestSigner::HEADER_SIGNATURE),
                RequestSigner::HEADER_TIMESTAMP => $signed->getHeaderLine(RequestSigner::HEADER_TIMESTAMP),
                RequestSigner::HEADER_KEY_ID => $signed->getHeaderLine(RequestSigner::HEADER_KEY_ID),
            ],
            body: 'tampered',
        );

        $response = $middleware->process($tampered, $this->createHandler());

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function logs_failed_verification_to_audit(): void
    {
        $entry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->willReturn($entry);

        $signer = new RequestSigner($this->key, 'key-1');
        $middleware = new RequestSignatureMiddleware($signer, auditLogger: $auditLogger);

        $request = new ServerRequest(method: 'GET', uri: '/api');
        $middleware->process($request, $this->createHandler());
    }

    #[Test]
    public function dispatches_threat_event_on_failure(): void
    {
        $dispatcher = $this->createMock(ThreatEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn(ThreatEvent $e): bool => $e->category === ThreatCategory::RequestTampering,
            ));

        $signer = new RequestSigner($this->key, 'key-1');
        $middleware = new RequestSignatureMiddleware($signer, eventDispatcher: $dispatcher);

        $request = new ServerRequest(method: 'GET', uri: '/api');
        $middleware->process($request, $this->createHandler());
    }
}
