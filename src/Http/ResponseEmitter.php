<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Response\StreamedResponse;
use RuntimeException;

use function header_remove;
use function is_string;
use function sprintf;
use function str_replace;
use function strlen;
use function strtoupper;

/**
 * Emits an HTTP response to the client.
 * @api
 */
#[Api(since: '1.0.0')]
final class ResponseEmitter
{
    /**
     * Fingerprinting headers the SAPI registers before user code runs.
     *
     * PHP's `expose_php=On` registers `X-Powered-By: PHP/x.y.z` and the CLI
     * built-in server registers a versioned `Server` value. Neither passes
     * through the PSR-7 response, so neither can be stripped by a middleware —
     * they must be removed here, at emit time. Advertising the runtime or its
     * version only helps an attacker match known CVEs (OWASP ASVS V14.4.1).
     *
     * @var list<string>
     */
    private const array STRIPPED_SAPI_HEADERS = ['X-Powered-By', 'Server'];

    /**
     * Emit the response to the client.
     *
     * Sends the status line and headers, then the body. Pass the request method
     * so a HEAD request emits headers (including Content-Length) without a body,
     * per RFC 9110. Should only be called once.
     *
     * @codeCoverageIgnore Emits HTTP headers/body via PHP built-ins: requires live SAPI
     */
    public function emit(ResponseInterface $response, ?string $requestMethod = null): void
    {
        $this->assertHeadersNotSent();

        $this->stripSapiFingerprintHeaders();
        $this->emitStatusLine($response);
        $this->emitHeaders($response);

        if ($this->shouldEmitBody($requestMethod)) {
            $this->emitBody($response);

            return;
        }

        // HEAD: no body is written, so the SAPI cannot derive Content-Length
        // from output and would advertise 0 -- which RFC 9110 §8.6 forbids when
        // it differs from the equivalent GET. Emit the real body size instead.
        $headContentLength = $this->headContentLength($response);

        if ($headContentLength !== null) {
            header('Content-Length: ' . $headContentLength, true);
        }
    }

    /**
     * Content-Length value a HEAD response must advertise, or null to omit it.
     *
     * RFC 9110 §8.6: a server MUST NOT send a Content-Length on a HEAD response
     * that differs from what the equivalent GET would have carried. When the
     * response already declares one it passes through {@see emitHeaders()}
     * untouched (null here); a streamed response has no length on GET either
     * (chunked), and an unknown body size MAY be omitted. Otherwise the body's
     * actual size -- what the SAPI would have derived for GET -- is the value.
     */
    public function headContentLength(ResponseInterface $response): ?int
    {
        if ($response->hasHeader('Content-Length')) {
            return null;
        }

        if ($response instanceof StreamedResponse) {
            return null;
        }

        return $response->getBody()->getSize();
    }

    /**
     * Drop SAPI-registered fingerprinting headers before emitting the response.
     *
     * Runs before {@see emitHeaders()} so that a value an application
     * deliberately places on the PSR-7 response (e.g. a custom `Server`) is
     * still emitted — only the SAPI defaults are removed. Proxy-injected
     * headers (nginx/Apache `Server`) are out of PHP's reach and must be
     * stripped at the web-server layer instead.
     *
     * @codeCoverageIgnore Calls header_remove(): requires live SAPI
     */
    private function stripSapiFingerprintHeaders(): void
    {
        foreach (self::STRIPPED_SAPI_HEADERS as $name) {
            header_remove($name);
        }
    }

    /**
     * Whether the response body should be written. A HEAD response carries the
     * same headers as the equivalent GET but never a body (RFC 9110 §9.3.2).
     */
    private function shouldEmitBody(?string $requestMethod): bool
    {
        return $requestMethod === null || strtoupper($requestMethod) !== 'HEAD';
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
     * A StreamedResponse advertises Transfer-Encoding: chunked and must be
     * written chunk-by-chunk with chunked framing and flushed as it is produced,
     * rather than materialized into one buffer via getBody().
     *
     * @codeCoverageIgnore Emits HTTP body via echo: requires live SAPI
     */
    private function emitBody(ResponseInterface $response): void
    {
        if ($response instanceof StreamedResponse) {
            $this->emitChunked($response);

            return;
        }

        $body = $response->getBody();

        if ($body->getSize() === 0) {
            return;
        }

        echo $body;
    }

    /**
     * Stream a response with HTTP/1.1 chunked transfer-encoding framing, flushing
     * each chunk so it reaches the client as it is generated.
     *
     * @codeCoverageIgnore Emits chunked body via echo/flush: requires live SAPI
     */
    private function emitChunked(StreamedResponse $response): void
    {
        /** @var mixed $chunk */
        foreach ($response->getSource() as $chunk) {
            $data = is_string($chunk) ? $chunk : '';

            if ($data !== '') {
                echo $this->chunkFrame($data);
                flush();
            }
        }

        // Final zero-length chunk terminates the stream.
        echo "0\r\n\r\n";
        flush();
    }

    /**
     * Frame one chunk for chunked transfer encoding: byte length in hex, CRLF,
     * the data, CRLF.
     */
    private function chunkFrame(string $data): string
    {
        return sprintf("%x\r\n%s\r\n", strlen($data), $data);
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
