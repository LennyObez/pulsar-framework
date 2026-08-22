<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Override;
use Pulsar\Api\Internal;
use RuntimeException;

use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;

/**
 * Default cURL-backed HTTP client for API-based mail transports.
 *
 * Ships with the framework (ext-curl is a hard requirement) so that
 * `MAIL_DRIVER=mailgun|ses|postmark|sendgrid` works with no application wiring.
 * TLS peer and host verification are always enforced, and both the connect and
 * total transfer time are bounded so a hung provider cannot stall a worker.
 *
 * When an application provides its own PSR-18 client, MailWiring prefers
 * {@see Psr18MailHttpClient} so mail reuses the app's HTTP stack instead.
 *
 * Transport-level failures (DNS, TLS, timeout) throw a {@see RuntimeException};
 * the calling transport wraps it in a driver-scoped MailException. An actual
 * HTTP response — including 4xx/5xx — is returned as a {@see MailHttpResponse}
 * so the transport can surface the provider's status and body.
 */
#[Internal]
final readonly class CurlMailHttpClient implements MailHttpClientInterface
{
    public function __construct(
        private int $timeoutSeconds = 30,
        private int $connectTimeoutSeconds = 10,
    ) {}

    #[Override]
    public function request(string $method, string $url, array $headers, string $body): MailHttpResponse
    {
        if ($method === '') {
            throw new RuntimeException('Mail HTTP request method must not be empty');
        }

        if ($url === '') {
            throw new RuntimeException('Mail HTTP request URL must not be empty');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new RuntimeException('Failed to initialize cURL handle for mail transport');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($handle);

        if ($result === false) {
            // curl_close() is a deprecated no-op since PHP 8.0; the CurlHandle is
            // freed automatically when $handle goes out of scope.
            throw new RuntimeException('cURL request to mail provider failed: ' . curl_error($handle));
        }

        // CURLINFO_RESPONSE_CODE is always an int; curl_getinfo()'s two-argument
        // form is typed as mixed because its return depends on the option.
        return new MailHttpResponse(
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            is_string($result) ? $result : '',
        );
    }
}
