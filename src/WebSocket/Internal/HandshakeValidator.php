<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Internal;

use Pulsar\Api\Internal;

use function base64_encode;
use function sha1;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Validates and processes WebSocket upgrade handshakes (RFC 6455 Section 4.2).
 */
#[Internal]
final readonly class HandshakeValidator
{
    /**
     * The WebSocket magic GUID used in the handshake (RFC 6455 Section 4.2.2).
     */
    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-5AB5DC11D700';

    /**
     * Validate an HTTP upgrade request for WebSocket.
     *
     * @param array<string, string> $headers Lowercase header name → value
     * @return HandshakeResult
     */
    public function validate(string $method, array $headers): HandshakeResult
    {
        if ($method !== 'GET') {
            return HandshakeResult::rejected('WebSocket upgrade requires GET method');
        }

        $upgrade = $headers['upgrade'] ?? '';

        if (strtolower(trim($upgrade)) !== 'websocket') {
            return HandshakeResult::rejected('Missing or invalid Upgrade header');
        }

        $connection = strtolower($headers['connection'] ?? '');

        if (!str_contains($connection, 'upgrade')) {
            return HandshakeResult::rejected('Missing Upgrade in Connection header');
        }

        $version = $headers['sec-websocket-version'] ?? '';

        if ($version !== '13') {
            return HandshakeResult::rejected('Unsupported WebSocket version: ' . $version);
        }

        $key = $headers['sec-websocket-key'] ?? '';

        if ($key === '') {
            return HandshakeResult::rejected('Missing Sec-WebSocket-Key header');
        }

        $acceptKey = $this->computeAcceptKey($key);

        return HandshakeResult::accepted($acceptKey);
    }

    /**
     * Compute the Sec-WebSocket-Accept value (RFC 6455 Section 4.2.2).
     */
    public function computeAcceptKey(string $clientKey): string
    {
        return base64_encode(sha1($clientKey . self::WEBSOCKET_GUID, true));
    }

    /**
     * Build the HTTP 101 Switching Protocols response.
     */
    public function buildAcceptResponse(string $acceptKey): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . $acceptKey . "\r\n"
            . "\r\n";
    }
}
