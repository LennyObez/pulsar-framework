<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;
use function str_replace;

/**
 * Emits an HTTP response to the client.
 */
#[Api(since: '1.0.0')]
final class ResponseEmitter
{
    /**
     * Emit the response to the client.
     *
     * This sends headers and outputs the body. Should only be called once.
     *
     * @codeCoverageIgnore Emits HTTP headers/body via PHP built-ins: requires live SAPI
     */
    public function emit(ResponseInterface $response): void
    {
        $this->assertHeadersNotSent();

        $this->emitStatusLine($response);
        $this->emitHeaders($response);
        $this->emitBody($response);
    }

    /**
     * Emit the HTTP status line.
     *
     * @codeCoverageIgnore Emits HTTP headers via PHP built-ins: requires live SAPI
     */
    private function emitStatusLine(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        header(
            'HTTP/' . $response->getProtocolVersion() . ' ' . $statusCode . ' ' . $this->sanitizeHeaderValue($response->getReasonPhrase()),
            true,
            $statusCode,
        );
    }

    /**
     * Emit all response headers.
     *
     * @codeCoverageIgnore Emits HTTP headers via PHP built-ins: requires live SAPI
     */
    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header($name . ': ' . $this->sanitizeHeaderValue($value), $first);
                $first = false;
            }
        }
    }

    /**
     * Emit the response body.
     *
     * @codeCoverageIgnore Emits HTTP body via echo: requires live SAPI
     */
    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->getSize() === 0) {
            return;
        }

        echo $body;
    }

    /**
     * Strip CRLF sequences to prevent header injection.
     */
    private function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], '', $value);
    }

    /**
     * @throws RuntimeException If headers were already sent
     *
     * @codeCoverageIgnore Checks headers_sent(): requires live SAPI
     */
    private function assertHeadersNotSent(): void
    {
        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf(
                'Headers already sent in %s on line %d. Cannot emit response.',
                $file,
                $line,
            ));
        }
    }
}
