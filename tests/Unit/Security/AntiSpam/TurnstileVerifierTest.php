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
use Pulsar\Security\AntiSpam\TurnstileVerifier;

#[CoversClass(TurnstileVerifier::class)]
final class TurnstileVerifierTest extends TestCase
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
        $verifier = new TurnstileVerifier($httpClient, $this->logger, 'site', 'secret');

        self::assertSame('captcha', $verifier->name());
    }

    #[Test]
    public function failsWhenTokenIsMissing(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $verifier = new TurnstileVerifier($httpClient, $this->logger, 'site', 'secret');

        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');
        $result = $verifier->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('missing', $result->reason ?? '');
    }

    #[Test]
    public function passesWithValidToken(): void
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('ok')->willReturn(true);
        $response->method('json')->willReturn(['success' => true]);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new TurnstileVerifier($httpClient, $this->logger, 'site', 'secret');
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip', captchaToken: 'valid');
        $result = $verifier->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsWithInvalidToken(): void
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('ok')->willReturn(true);
        $response->method('json')->willReturn(['success' => false]);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new TurnstileVerifier($httpClient, $this->logger, 'site', 'secret');
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip', captchaToken: 'invalid');
        $result = $verifier->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function failsOnNetworkError(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willThrowException(
            HttpClientException::connectionFailed('https://challenges.cloudflare.com/turnstile/v0/siteverify', 'Timeout'),
        );

        $verifier = new TurnstileVerifier($httpClient, $this->logger, 'site', 'secret');

        self::assertFalse($verifier->verify('token', 'ip'));
    }

    #[Test]
    public function failsOnServerError(): void
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('ok')->willReturn(false);
        $response->method('status')->willReturn(503);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new TurnstileVerifier($httpClient, $this->logger, 'site', 'secret');

        self::assertFalse($verifier->verify('token', 'ip'));
    }

    #[Test]
    public function verifyReturnsFalseForNonArrayJson(): void
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('ok')->willReturn(true);
        $response->method('json')->willReturn('string-response');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('post')->willReturn($response);

        $verifier = new TurnstileVerifier($httpClient, $this->logger, 'site', 'secret');

        self::assertFalse($verifier->verify('token', 'ip'));
    }
}
