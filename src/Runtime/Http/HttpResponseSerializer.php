<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Http;

use function gmdate;

use Pulsar\Api\Internal;
use Pulsar\Http\Method;
use Pulsar\Http\Response;

use function sprintf;
use function strlen;

/**
 * Serializes a Pulsar Response into raw HTTP/1.1 bytes for socket writing.
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
        Response $response,
        ?Method $requestMethod = null,
        bool $closeConnection = false,
        bool $addDateHeader = true,
    ): string {
        $status = $response->status;
        $statusLine = sprintf(
            "HTTP/%s %d %s\r\n",
            $response->protocolVersion,
            $status->value,
            $status->reasonPhrase(),
        );

        // Build headers — start with response headers
        $headers = $response->headers;

        // Strip Transfer-Encoding to prevent framing ambiguity
        if ($headers->has('Transfer-Encoding')) {
            $headers = $headers->without('Transfer-Encoding');
        }

        // Set Content-Length from actual body length
        $bodyLength = strlen($response->body);
        $headers = $headers->with('Content-Length', (string) $bodyLength);

        // Inject Connection: close if closing
        if ($closeConnection) {
            $headers = $headers->with('Connection', 'close');
        }

        // Add Date header if enabled and not already present
        if ($addDateHeader && !$headers->has('Date')) {
            $headers = $headers->with('Date', gmdate('D, d M Y H:i:s') . ' GMT');
        }

        // Build header string
        $headerStr = '';

        foreach ($headers->toArray() as $name => $values) {
            foreach ($values as $value) {
                $headerStr .= sprintf("%s: %s\r\n", $name, $value);
            }
        }

        // HEAD responses: include Content-Length but omit body
        $body = ($requestMethod === Method::HEAD) ? '' : $response->body;

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
