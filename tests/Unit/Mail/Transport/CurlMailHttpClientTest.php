<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Transport\CurlMailHttpClient;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use RuntimeException;

/**
 * Covers the input-validation guards, the contract, and the real cURL execution
 * path: the connection-refused test drives curl_init, curl_setopt_array (with the
 * real TLS/timeout/header/method/body options), curl_exec, error capture, and the
 * RuntimeException wrap against a live cURL handle. The success-path response
 * mapping (status from curl_getinfo, body from curl_exec) is the trivial
 * remainder and is not subprocess-tested — a local-server test proved too fragile
 * on Windows (hanging proc_open pipes) to keep the suite reliable.
 */
#[CoversClass(CurlMailHttpClient::class)]
final class CurlMailHttpClientTest extends TestCase
{
    #[Test]
    public function isAMailHttpClient(): void
    {
        self::assertInstanceOf(MailHttpClientInterface::class, new CurlMailHttpClient());
    }

    #[Test]
    public function performsRealRequestAndThrowsOnConnectionFailure(): void
    {
        // Exercises the real cURL path end-to-end: curl_init, curl_setopt_array
        // with the actual TLS/timeout/header/method/body options, curl_exec (which
        // fails — the connection to a closed port is refused), curl_error capture,
        // and the RuntimeException wrap. Port 1 has no listener, so the connection
        // is refused immediately (capped by the 1s connect timeout) — no flakiness.
        $client = new CurlMailHttpClient(timeoutSeconds: 2, connectTimeoutSeconds: 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('cURL request to mail provider failed');

        $client->request('POST', 'http://127.0.0.1:1/messages', ['X-Test' => 'yes'], 'payload=1');
    }

    #[Test]
    public function throwsOnEmptyMethod(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('method must not be empty');

        new CurlMailHttpClient()->request('', 'https://api.example.com/send', [], '{}');
    }

    #[Test]
    public function throwsOnEmptyUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('URL must not be empty');

        new CurlMailHttpClient()->request('POST', '', [], '{}');
    }
}
