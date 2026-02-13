<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Http;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Internal;

use function gmdate;
use function strlen;
use function strtolower;

/**
 * Serializes a PSR-7 Response into raw HTTP/1.1 bytes for socket writing.
 *
 * Guarantees:
 * - Content-Length always set (required for keep-alive framing)
 * - Transfer-Encoding stripped (no chunked on responses in rc.6)
 * - Connection: close injected when server decides to close
 * - HEAD responses: Content-Length matches body length, body omitted
 * - Date header added unless disabled or already present
 */
#[Internal]
final class HttpResponseSerializer
{
    /**
     * Cached Date header value, updated once per second.
     */
    private string $cachedDate = '';

    private int $cachedDateTimestamp = 0;

    /**
     * Serialize a Response to raw HTTP bytes.
     *
     * @param bool $closeConnection Whether to inject Connection: close
     * @param bool $addDateHeader Whether to add a Date header if not present
     */
    public function serialize(
        ResponseInterface $response,
        ?string $requestMethod = null,
        bool $closeConnection = false,
        bool $addDateHeader = true,
    ): string {
        $statusCode = $response->getStatusCode();
        $statusLine = 'HTTP/' . $response->getProtocolVersion() . ' ' . $statusCode . ' ' . $response->getReasonPhrase() . "\r\n";

        // Read body and compute length
        $bodyString = (string) $response->getBody();
        $bodyLength = strlen($bodyString);

        // Build header string, handling overrides
        $headerStr = '';
        $hasDate = false;

        /** @var array<string, list<string>> $allHeaders */
        $allHeaders = $response->getHeaders();

        foreach ($allHeaders as $name => $values) {
            $lower = strtolower($name);

            // Strip Transfer-Encoding to prevent framing ambiguity
            if ($lower === 'transfer-encoding') {
                continue;
            }

            // Skip Content-Length: we'll set our own from actual body
            if ($lower === 'content-length') {
                continue;
            }

            // Skip Connection if we're injecting our own
            if ($closeConnection && $lower === 'connection') {
                continue;
            }

            if ($lower === 'date') {
                $hasDate = true;
            }

            foreach ($values as $value) {
                $headerStr .= $name . ': ' . $value . "\r\n";
            }
        }

        // Set Content-Length from actual body length
        $headerStr .= 'Content-Length: ' . $bodyLength . "\r\n";

        // Inject Connection: close if closing
        if ($closeConnection) {
            $headerStr .= "Connection: close\r\n";
        }

        // Add Date header if enabled and not already present
        if ($addDateHeader && !$hasDate) {
            $headerStr .= 'Date: ' . $this->currentDate() . "\r\n";
        }

        // HEAD responses: include Content-Length but omit body
        $body = ($requestMethod === 'HEAD') ? '' : $bodyString;

        return $statusLine . $headerStr . "\r\n" . $body;
    }

    /**
     * Create a minimal error response as raw bytes.
     */
    public static function errorResponse(
        int $statusCode,
        string $reasonPhrase,
        string $body = '',
        bool $addDateHeader = true,
    ): string {
        if ($body === '') {
            $body = $reasonPhrase;
        }

        $bodyLength = strlen($body);
        $date = $addDateHeader ? 'Date: ' . gmdate('D, d M Y H:i:s') . " GMT\r\n" : '';

        return 'HTTP/1.1 ' . $statusCode . ' ' . $reasonPhrase . "\r\n"
            . $date
            . 'Content-Length: ' . $bodyLength . "\r\n"
            . "Connection: close\r\nContent-Type: text/plain\r\n\r\n"
            . $body;
    }

    /**
     * Get the current RFC 7231 formatted date, cached per second.
     *
     * In persistent runtimes this avoids calling gmdate() on every
     * response when multiple requests are handled within the same second.
     */
    private function currentDate(): string
    {
        $now = time();

        if ($now !== $this->cachedDateTimestamp) {
            $this->cachedDateTimestamp = $now;
            $this->cachedDate = gmdate('D, d M Y H:i:s') . ' GMT';
        }

        return $this->cachedDate;
    }
}
