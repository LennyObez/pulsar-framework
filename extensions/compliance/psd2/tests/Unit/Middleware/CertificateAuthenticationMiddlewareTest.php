<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Middleware;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Psd2\Contracts\CertificateValidatorInterface;
use Pulsar\Extension\Psd2\Domain\CertificateInfo;
use Pulsar\Extension\Psd2\Domain\CertificateType;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Extension\Psd2\Middleware\CertificateAuthenticationMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

final class CertificateAuthenticationMiddlewareTest extends TestCase
{
    private CertificateValidatorInterface&Stub $validator;

    protected function setUp(): void
    {
        $this->validator = $this->createStub(CertificateValidatorInterface::class);
    }

    #[Test]
    public function rejectsRequestWithNoCertificate(): void
    {
        $middleware = new CertificateAuthenticationMiddleware($this->validator);
        $request = new ServerRequest('GET', '/api/open-banking/accounts');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidCertificate(): void
    {
        $this->validator->method('validate')
            ->willThrowException(Psd2Exception::certificateParseFailure('bad cert'));

        $middleware = new CertificateAuthenticationMiddleware($this->validator);
        $request = new ServerRequest('GET', '/api/open-banking/accounts')
            ->withHeader(CertificateAuthenticationMiddleware::CERT_HEADER, 'invalid-pem');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function rejectsUnauthorizedProvider(): void
    {
        $certInfo = $this->createCertInfo();
        $this->validator->method('validate')->willReturn($certInfo);
        $this->validator->method('isAuthorized')->willReturn(false);

        $middleware = new CertificateAuthenticationMiddleware($this->validator);
        $request = new ServerRequest('GET', '/api/open-banking/accounts')
            ->withHeader(CertificateAuthenticationMiddleware::CERT_HEADER, 'valid-pem');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function allowsAuthorizedCertificateAndSetAttribute(): void
    {
        $certInfo = $this->createCertInfo();
        $this->validator->method('validate')->willReturn($certInfo);
        $this->validator->method('isAuthorized')->willReturn(true);

        $middleware = new CertificateAuthenticationMiddleware($this->validator);
        $request = new ServerRequest('GET', '/api/open-banking/accounts')
            ->withHeader(CertificateAuthenticationMiddleware::CERT_HEADER, 'valid-pem');

        $capturedRequest = null;
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $req) use (&$capturedRequest): Response {
                $capturedRequest = $req;

                return new Response(statusCode: 200, body: 'OK');
            });

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(
            CertificateInfo::class,
            $capturedRequest->getAttribute(CertificateAuthenticationMiddleware::CERT_ATTRIBUTE),
        );
    }

    #[Test]
    public function returnsJsonForJsonAcceptHeader(): void
    {
        $middleware = new CertificateAuthenticationMiddleware($this->validator);
        $request = new ServerRequest('GET', '/api/open-banking/accounts')
            ->withHeader('Accept', 'application/json');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    private function createCertInfo(): CertificateInfo
    {
        return new CertificateInfo(
            type: CertificateType::Qwac,
            subject: 'CN=Test Bank QWAC',
            issuer: 'CN=Test CA',
            serialNumber: 'ABC123',
            authorizationNumber: 'PSDFR-ACPR-12345',
            psd2Roles: ['PSP_AI'],
            ncaName: 'ACPR',
            ncaId: 'FR-ACPR',
            validFrom: new DateTimeImmutable('2025-01-01'),
            validUntil: new DateTimeImmutable('2027-01-01'),
            isQualified: true,
        );
    }
}
