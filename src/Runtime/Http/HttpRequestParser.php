<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Http;

use Pulsar\Api\Internal;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use ValueError;

use function array_filter;
use function array_key_exists;
use function count;
use function explode;
use function intval;
use function is_string;
use function ltrim;
use function parse_str;
use function rawurldecode;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const ARRAY_FILTER_USE_KEY;

/**
 * Incremental HTTP/1.1 request parser operating on a ConnectionContext buffer.
 *
 * Parses one complete request from the buffer, leaving remaining bytes
 * for subsequent parse calls (pipelining support).
 */
#[Internal]
final class HttpRequestParser
{
    private const string HEADER_DELIMITER = "\r\n\r\n";
    private const int MAX_HEADER_COUNT = 100;

    /**
     * Attempt to parse one complete HTTP request from the connection buffer.
     *
     * @return Request|Response|null Request on success, Response on parse error (to send and close), null if incomplete
     */
    public function parse(
        ConnectionContext $ctx,
        int $maxHeaderSize = 8192,
        int $maxBodySize = 10_485_760,
    ): Request|Response|null {
        $buffer = $ctx->readBuffer;

        // Check if we have complete headers
        $headerEnd = strpos($buffer, self::HEADER_DELIMITER);

        if ($headerEnd === false) {
            // Headers not yet complete — check if already too large
            if (strlen($buffer) > $maxHeaderSize) {
                $ctx->readBuffer = '';

                return new Response(
                    body: 'Request Header Fields Too Large',
                    status: ResponseStatus::RequestHeaderFieldsTooLarge,
                );
            }

            return null; // Need more data
        }

        $headerSection = substr($buffer, 0, $headerEnd);

        // Check header size limit (includes request line)
        if (strlen($headerSection) > $maxHeaderSize) {
            $ctx->readBuffer = '';

            return new Response(
                body: 'Request Header Fields Too Large',
                status: ResponseStatus::RequestHeaderFieldsTooLarge,
            );
        }

        // Parse request line
        $lines = explode("\r\n", $headerSection);
        $requestLine = $lines[0];
        $parts = explode(' ', $requestLine, 3);

        if (count($parts) !== 3) {
            $ctx->readBuffer = '';

            return new Response(
                body: 'Bad Request',
                status: ResponseStatus::BadRequest,
            );
        }

        [$methodStr, $uri, $protocolStr] = $parts;

        try {
            $method = Method::fromString($methodStr);
        } catch (ValueError) {
            $ctx->readBuffer = '';

            return new Response(
                body: 'Bad Request',
                status: ResponseStatus::BadRequest,
            );
        }

        // Parse protocol version
        $slashPos = strpos($protocolStr, '/');

        if ($slashPos === false) {
            $ctx->readBuffer = '';

            return new Response(
                body: 'Bad Request',
                status: ResponseStatus::BadRequest,
            );
        }

        $protocolVersion = substr($protocolStr, $slashPos + 1);

        // Parse headers
        $headers = [];
        $headerCount = 0;

        for ($i = 1, $lineCount = count($lines); $i < $lineCount; $i++) {
            $line = $lines[$i];

            if ($line === '') {
                continue;
            }

            $colonPos = strpos($line, ':');

            if ($colonPos === false) {
                $ctx->readBuffer = '';

                return new Response(
                    body: 'Bad Request',
                    status: ResponseStatus::BadRequest,
                );
            }

            $headerCount++;

            if ($headerCount > self::MAX_HEADER_COUNT) {
                $ctx->readBuffer = '';

                return new Response(
                    body: 'Request Header Fields Too Large',
                    status: ResponseStatus::RequestHeaderFieldsTooLarge,
                );
            }

            $name = trim(substr($line, 0, $colonPos));
            $value = ltrim(substr($line, $colonPos + 1));

            if (array_key_exists($name, $headers)) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        $headerBag = new HeaderBag($headers);

        // Determine body handling
        $transferEncoding = $headerBag->first('Transfer-Encoding');
        $contentLengthHeader = $headerBag->first('Content-Length');
        $bodyStartOffset = $headerEnd + strlen(self::HEADER_DELIMITER);
        $body = '';

        // RFC 9112 Section 6.3: chunked + Content-Length = reject
        if ($transferEncoding !== null && $contentLengthHeader !== null) {
            $ctx->readBuffer = '';

            return new Response(
                body: 'Bad Request',
                status: ResponseStatus::BadRequest,
            );
        }

        if ($transferEncoding !== null) {
            $normalized = strtolower(trim($transferEncoding));

            if ($normalized !== 'chunked') {
                $ctx->readBuffer = '';

                return new Response(
                    body: 'Not Implemented',
                    status: ResponseStatus::NotImplemented,
                );
            }

            // Decode chunked body
            $result = $this->decodeChunkedBody(
                substr($buffer, $bodyStartOffset),
                $maxBodySize,
            );

            if ($result === null) {
                return null; // Need more data
            }

            if ($result instanceof Response) {
                $ctx->readBuffer = '';

                return $result;
            }

            /** @var array{body: string, consumed: int} $result */
            $body = $result['body'];
            $totalConsumed = $bodyStartOffset + $result['consumed'];
            $ctx->readBuffer = substr($buffer, $totalConsumed);
        } elseif ($contentLengthHeader !== null) {
            $contentLength = intval($contentLengthHeader);

            if ($contentLength < 0) {
                $ctx->readBuffer = '';

                return new Response(
                    body: 'Bad Request',
                    status: ResponseStatus::BadRequest,
                );
            }

            if ($contentLength > $maxBodySize) {
                $ctx->readBuffer = '';

                return new Response(
                    body: 'Payload Too Large',
                    status: ResponseStatus::PayloadTooLarge,
                );
            }

            $availableBody = strlen($buffer) - $bodyStartOffset;

            if ($availableBody < $contentLength) {
                return null; // Need more data
            }

            $body = substr($buffer, $bodyStartOffset, $contentLength);
            $ctx->readBuffer = substr($buffer, $bodyStartOffset + $contentLength);
        } else {
            // No body
            $ctx->readBuffer = substr($buffer, $bodyStartOffset);
        }

        // Parse URI components
        $path = $uri;
        $queryString = '';
        $queryPos = strpos($uri, '?');

        if ($queryPos !== false) {
            $path = substr($uri, 0, $queryPos);
            $queryString = substr($uri, $queryPos + 1);
        }

        $path = rawurldecode($path);

        $queryParams = $this->parseQueryParams($queryString);

        // Parse cookies from Cookie header
        /** @var array<string, string> $cookies */
        $cookies = [];
        $cookieHeader = $headerBag->first('Cookie');

        if ($cookieHeader !== null) {
            foreach (explode(';', $cookieHeader) as $cookie) {
                $cookie = trim($cookie);
                $eqPos = strpos($cookie, '=');

                if ($eqPos !== false) {
                    $cookies[trim(substr($cookie, 0, $eqPos))] = trim(substr($cookie, $eqPos + 1));
                }
            }
        }

        return new Request(
            method: $method,
            uri: $uri,
            path: $path,
            queryString: $queryString,
            headers: $headerBag,
            body: $body,
            query: $queryParams,
            cookies: $cookies,
            protocolVersion: $protocolVersion,
        );
    }

    /**
     * Decode a chunked transfer-encoded body.
     *
     * @return array{body: string, consumed: int}|Response|null
     *         Array on success, Response on error, null if incomplete
     */
    private function decodeChunkedBody(string $data, int $maxBodySize): array|Response|null
    {
        $body = '';
        $offset = 0;
        $dataLen = strlen($data);

        while (true) {
            // Find chunk size line
            $lineEnd = strpos($data, "\r\n", $offset);

            if ($lineEnd === false) {
                return null; // Need more data
            }

            $chunkLine = substr($data, $offset, $lineEnd - $offset);

            // Check for chunk extensions (semicolon-separated)
            $semiPos = strpos($chunkLine, ';');

            if ($semiPos !== false) {
                $extension = substr($chunkLine, $semiPos + 1);

                if (strlen($extension) > 256) {
                    return new Response(
                        body: 'Bad Request',
                        status: ResponseStatus::BadRequest,
                    );
                }

                $chunkLine = substr($chunkLine, 0, $semiPos);
            }

            $chunkSize = (int) hexdec(trim($chunkLine));

            if ($chunkSize === 0) {
                // Terminal chunk — skip trailing \r\n after 0-chunk
                $terminator = $lineEnd + 2;

                // Look for trailer end (\r\n after 0\r\n)
                $trailerEnd = strpos($data, "\r\n", $terminator);

                if ($trailerEnd === false) {
                    // Might need more data for the trailing CRLF
                    if ($dataLen < $terminator + 2) {
                        return null;
                    }

                    // No trailers, just consume 0\r\n\r\n
                    return [
                        'body' => $body,
                        'consumed' => $terminator + 2,
                    ];
                }

                // Silently ignore trailers — find the final empty line
                $pos = $terminator;

                while ($pos < $dataLen) {
                    $nextLine = strpos($data, "\r\n", $pos);

                    if ($nextLine === false) {
                        return null; // Need more data
                    }

                    if ($nextLine === $pos) {
                        // Empty line = end of trailers
                        return [
                            'body' => $body,
                            'consumed' => $pos + 2,
                        ];
                    }

                    $pos = $nextLine + 2;
                }

                return null; // Need more data
            }

            // Check decoded body size limit
            if (strlen($body) + $chunkSize > $maxBodySize) {
                return new Response(
                    body: 'Payload Too Large',
                    status: ResponseStatus::PayloadTooLarge,
                );
            }

            // Read chunk data
            $chunkDataStart = $lineEnd + 2;
            $chunkEnd = $chunkDataStart + $chunkSize + 2; // +2 for trailing \r\n

            if ($dataLen < $chunkEnd) {
                return null; // Need more data
            }

            $body .= substr($data, $chunkDataStart, $chunkSize);
            $offset = $chunkEnd;
        }
    }

    /**
     * Parse a query string into a string-keyed parameter array.
     *
     * @psalm-suppress MixedReturnTypeCoercion — Psalm cannot narrow key types through ARRAY_FILTER_USE_KEY
     *
     * @return array<string, mixed>
     */
    private function parseQueryParams(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }

        $raw = [];
        parse_str($queryString, $raw);

        /** @psalm-suppress MixedReturnTypeCoercion */
        return array_filter($raw, static fn(int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
