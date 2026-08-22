<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Client;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Client\HttpClientException;
use Pulsar\Http\ResponseStatus;

use function strlen;

#[CoversClass(HttpClientException::class)]
final class HttpClientExceptionTest extends TestCase
{
    #[Test]
    public function requestFailedIncludesStatusAndTruncatedBody(): void
    {
        $exception = HttpClientException::requestFailed(
            ResponseStatus::NotFound,
            'Page not found',
        );

        self::assertSame(404, $exception->getCode());
        self::assertStringContainsString('404', $exception->getMessage());
        self::assertStringContainsString('Not Found', $exception->getMessage());
        self::assertStringContainsString('Page not found', $exception->getMessage());
    }

    #[Test]
    public function requestFailedTruncatesLongBody(): void
    {
        $longBody = str_repeat('x', 300);
        $exception = HttpClientException::requestFailed(ResponseStatus::BadRequest, $longBody);

        self::assertStringContainsString('...', $exception->getMessage());
        self::assertLessThan(400, strlen($exception->getMessage()));
    }

    #[Test]
    public function connectionFailedIncludesUrlAndReason(): void
    {
        $exception = HttpClientException::connectionFailed(
            'https://api.example.com/endpoint',
            'DNS resolution failed',
        );

        self::assertStringContainsString('https://api.example.com/endpoint', $exception->getMessage());
        self::assertStringContainsString('DNS resolution failed', $exception->getMessage());
    }

    #[Test]
    public function timeoutIncludesUrlAndDuration(): void
    {
        $exception = HttpClientException::timeout('https://slow.example.com', 30.0);

        self::assertStringContainsString('https://slow.example.com', $exception->getMessage());
        self::assertStringContainsString('30.0', $exception->getMessage());
        self::assertStringContainsString('timed out', $exception->getMessage());
    }

    #[Test]
    public function ssrfBlockedIncludesHostAndIp(): void
    {
        $exception = HttpClientException::ssrfBlocked('internal.corp', '10.0.0.1');

        self::assertStringContainsString('SSRF', $exception->getMessage());
        self::assertStringContainsString('internal.corp', $exception->getMessage());
        self::assertStringContainsString('10.0.0.1', $exception->getMessage());
    }

    #[Test]
    public function invalidJsonIncludesPreviousException(): void
    {
        $jsonException = new JsonException('Syntax error');
        $exception = HttpClientException::invalidJson($jsonException);

        self::assertStringContainsString('Syntax error', $exception->getMessage());
        self::assertSame($jsonException, $exception->getPrevious());
    }

    #[Test]
    public function responseTooLargeIncludesMaxSize(): void
    {
        $exception = HttpClientException::responseTooLarge(1_048_576);

        self::assertStringContainsString('1048576', $exception->getMessage());
        self::assertStringContainsString('exceeded', $exception->getMessage());
    }

    #[Test]
    public function tooManyRedirectsIncludesMax(): void
    {
        $exception = HttpClientException::tooManyRedirects(5);

        self::assertStringContainsString('5', $exception->getMessage());
        self::assertStringContainsString('redirects', $exception->getMessage());
    }

    #[Test]
    public function sslErrorIncludesUrlAndReason(): void
    {
        $exception = HttpClientException::sslError(
            'https://expired-cert.example.com',
            'Certificate has expired',
        );

        self::assertStringContainsString('https://expired-cert.example.com', $exception->getMessage());
        self::assertStringContainsString('Certificate has expired', $exception->getMessage());
        self::assertStringContainsString('SSL', $exception->getMessage());
    }
}
