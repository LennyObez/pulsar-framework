<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Protocol;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Exception\McpException;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * JSON-RPC 2.0 codec for MCP message encoding and decoding.
 *
 * Handles parse validation, protocol version checks, and compact JSON serialization.
 */
#[Internal]
final readonly class JsonRpcCodec
{
    private const string JSONRPC_VERSION = '2.0';
    private const int PARSE_ERROR = -32700;
    private const int INVALID_REQUEST = -32600;

    /**
     * Decode a JSON-RPC 2.0 request from a raw JSON string.
     *
     * @throws McpException On parse errors (-32700) or invalid requests (-32600)
     */
    public function decode(string $line): JsonRpcRequest
    {
        try {
            $data = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw McpException::protocolError('Parse error: ' . $e->getMessage(), self::PARSE_ERROR);
        }

        if (!is_array($data)) {
            throw McpException::protocolError('Request must be a JSON object', self::INVALID_REQUEST);
        }

        /** @var array<string, mixed> $data */
        $version = $data['jsonrpc'] ?? null;
        if ($version !== self::JSONRPC_VERSION) {
            throw McpException::protocolError(
                'Invalid or missing jsonrpc version, expected "2.0"',
                self::INVALID_REQUEST,
            );
        }

        $method = $data['method'] ?? null;
        if (!is_string($method) || $method === '') {
            throw McpException::protocolError('Missing or invalid method field', self::INVALID_REQUEST);
        }

        $id = $data['id'] ?? null;
        if ($id !== null && !is_string($id) && !is_int($id)) {
            throw McpException::protocolError('Request id must be a string, integer, or null', self::INVALID_REQUEST);
        }

        /** @var array<string, mixed> $params */
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        return new JsonRpcRequest(
            id: $id,
            method: $method,
            params: $params,
        );
    }

    /**
     * Encode a successful JSON-RPC 2.0 response.
     *
     * @param string|int $id Request ID
     * @param array<string, mixed> $result Result payload
     *
     * @return string Compact JSON with trailing newline
     */
    public function encodeResult(string|int $id, array $result): string
    {
        return json_encode(
            [
                'jsonrpc' => self::JSONRPC_VERSION,
                'id' => $id,
                'result' => $result,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /**
     * Encode a JSON-RPC 2.0 error response.
     *
     * @param string|int|null $id Request ID (null if the request could not be parsed)
     * @param int $code JSON-RPC error code
     * @param string $message Human-readable error message
     * @param array<string, mixed>|null $data Optional additional error data
     *
     * @return string Compact JSON with trailing newline
     */
    public function encodeError(string|int|null $id, int $code, string $message, ?array $data = null): string
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return json_encode(
            [
                'jsonrpc' => self::JSONRPC_VERSION,
                'id' => $id,
                'error' => $error,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }
}
