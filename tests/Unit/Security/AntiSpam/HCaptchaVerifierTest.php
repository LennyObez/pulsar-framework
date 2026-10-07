<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Http\Client\HttpClientException;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\HCaptchaVerifier;

#[CoversClass(HCaptchaVerifier::class)]
final class HCaptchaVerifierTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    #[Test]
    public function nameReturnsCaptcha(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');

        self::assertSame('captcha', $verifier->name());
    }

    #[Test]
    public function failsWhenTokenIsMissing(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');

        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');
        $result = $verifier->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('missing', $result->reason ?? '');
    }

    #[Test]
    public function failsWhenTokenIsEmpty(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');

        $context = new AntiSpamContext(body: 'test', ipHash: 'ip', captchaToken: '');
        $result = $verifier->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function passesWithValidToken(): void
    {
        $response = $this->createSuccessResponse(true);
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip', captchaToken: 'valid-token');
        $result = $verifier->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function sendsRealClientIpAsRemoteIpNotTheHash(): void
    {
        // FR-44: hCaptcha uses remoteip for its own IP risk scoring, so it must
        // receive the real client IP — not the internal ipHash, which would
        // defeat that scoring.
        $response = $this->createSuccessResponse(true);

        $captured = [];
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturnCallback(
            function (string $url, array $options) use (&$captured, $response): HttpResponse {
                $captured = $options;

                return $response;
            },
        );

        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'HASHED-ip-value',
            captchaToken: 'valid-token',
            ip: '203.0.113.42',
        );
        $verifier->check($context);

        self::assertArrayHasKey('form', $captured);
        $form = $captured['form'];
        self::assertIsArray($form);
        self::assertSame('203.0.113.42', $form['remoteip'] ?? null);
    }

    #[Test]
    public function failsWithInvalidToken(): void
    {
        $response = $this->createSuccessResponse(false);
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip', captchaToken: 'invalid-token');
        $result = $verifier->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function failsOnNetworkError(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willThrowException(
            HttpClientException::connectionFailed('https://api.hcaptcha.com/siteverify', 'Connection timeout'),
        );

        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip', captchaToken: 'token');
        $result = $verifier->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function failsOnNon200Response(): void
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('ok')->willReturn(false);
        $response->method('status')->willReturn(500);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');
        $verified = $verifier->verify('token', 'ip');

        self::assertFalse($verified);
    }

    #[Test]
    public function verifyReturnsTrueForSuccessfulResponse(): void
    {
        $response = $this->createSuccessResponse(true);
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');

        self::assertTrue($verifier->verify('valid-token', '127.0.0.1'));
    }

    #[Test]
    public function verifyReturnsFalseForNonArrayJson(): void
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('ok')->willReturn(true);
        $response->method('json')->willReturn('not-an-array');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new HCaptchaVerifier($httpClient, $this->logger, 'site', 'secret');

        self::assertFalse($verifier->verify('token', 'ip'));
    }

    private function createSuccessResponse(bool $success): HttpResponse
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('ok')->willReturn(true);
        $response->method('json')->willReturn(['success' => $success]);

        return $response;
    }
}
