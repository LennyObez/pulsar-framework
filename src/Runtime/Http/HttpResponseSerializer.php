<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Http;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Internal;

use function gmdate;
use function sprintf;
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
        $statusLine = sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        );

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

            // Skip Content-Length — we'll set our own from actual body
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
                $headerStr .= sprintf("%s: %s\r\n", $name, $value);
            }
        }

        // Set Content-Length from actual body length
        $headerStr .= sprintf("Content-Length: %d\r\n", $bodyLength);

        // Inject Connection: close if closing
        if ($closeConnection) {
            $headerStr .= "Connection: close\r\n";
        }

        // Add Date header if enabled and not already present
        if ($addDateHeader && !$hasDate) {
            $headerStr .= sprintf("Date: %s GMT\r\n", gmdate('D, d M Y H:i:s'));
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
        $date = $addDateHeader ? sprintf("Date: %s GMT\r\n", gmdate('D, d M Y H:i:s')) : '';

        return sprintf(
            "HTTP/1.1 %d %s\r\n%sContent-Length: %d\r\nConnection: close\r\nContent-Type: text/plain\r\n\r\n%s",
            $statusCode,
            $reasonPhrase,
            $date,
            $bodyLength,
            $body,
        );
    }
}
