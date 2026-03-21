<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Web;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;

use function base64_decode;
use function base64_encode;
use function chr;
use function pack;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function unpack;

/**
 * Thin adapter for unary gRPC-Web calls (development convenience only).
 *
 * Translates HTTP/1.1 POST requests with gRPC-Web content types to
 * gRPC unary calls, then formats the response in gRPC-Web wire format.
 *
 * For production use, prefer Envoy proxy with the gRPC-Web filter.
 * This adapter only supports unary RPCs: streaming is not supported.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GrpcWebAdapter
{
    public function __construct(
        private GrpcRequestHandler $handler,
    ) {}

    /**
     * Handle an incoming gRPC-Web request.
     *
     * @param string $method              HTTP method (must be POST)
     * @param string $path                Request path (e.g., "/package.Service/Method")
     * @param string $contentType         Content-Type header value
     * @param string $body                Raw request body
     * @param array<string, string> $headers  HTTP headers
     *
     * @return GrpcWebResponse The formatted gRPC-Web response
     */
    public function handle(
        string $method,
        string $path,
        string $contentType,
        string $body,
        array $headers = [],
    ): GrpcWebResponse {
        if ($method !== 'POST') {
            return GrpcWebResponse::error(
                GrpcStatus::InvalidArgument,
                'gRPC-Web requires POST method',
            );
        }

        $webContentType = GrpcWebContentType::fromHeader($contentType);

        if ($webContentType === null) {
            return GrpcWebResponse::error(
                GrpcStatus::InvalidArgument,
                sprintf('Unsupported content type: %s', $contentType),
            );
        }

        // Decode the request body
        $payload = $this->decodeRequestBody($body, $webContentType);

        if ($payload === null) {
            return GrpcWebResponse::error(
                GrpcStatus::InvalidArgument,
                'Failed to decode gRPC-Web request body',
            );
        }

        // Convert HTTP headers to gRPC metadata
        $metadata = $this->headersToMetadata($headers);

        // Dispatch to the gRPC handler
        $result = $this->handler->handle($path, $payload, $metadata);

        return $this->buildResponse($result, $webContentType);
    }

    /**
     * Decode the gRPC-Web request body.
     *
     * gRPC-Web uses a length-prefixed framing format:
     * - 1 byte: compression flag (0 = uncompressed)
     * - 4 bytes: message length (big-endian uint32)
     * - N bytes: message data
     */
    private function decodeRequestBody(string $body, GrpcWebContentType $contentType): ?string
    {
        if ($contentType->isTextEncoded()) {
            $decoded = base64_decode($body, true);

            if ($decoded === false) {
                return null;
            }

            $body = $decoded;
        }

        if (strlen($body) < 5) {
            return null;
        }

        // Skip compression flag (1 byte)
        $lengthBytes = substr($body, 1, 4);

        /** @var array{length: int}|false $unpacked */
        $unpacked = unpack('Nlength', $lengthBytes);

        if ($unpacked === false) {
            return null;
        }

        $messageLength = $unpacked['length'];
        $expectedTotal = 5 + $messageLength;

        if (strlen($body) < $expectedTotal) {
            return null;
        }

        return substr($body, 5, $messageLength);
    }

    /**
     * Build a gRPC-Web response from the interceptor result.
     */
    private function buildResponse(InterceptorResult $result, GrpcWebContentType $requestContentType): GrpcWebResponse
    {
        // Build the data frame (compressed=0 + length + data)
        $dataFrame = $this->encodeFrame(0, $result->payload);

        // Build the trailers frame (compressed=0x80 for trailers)
        $trailerString = sprintf("grpc-status:%d\r\n", $result->status->value);

        if ($result->message !== '') {
            $trailerString .= sprintf("grpc-message:%s\r\n", $result->message);
        }

        foreach ($result->trailers as $key => $values) {
            foreach ($values as $value) {
                $trailerString .= sprintf("%s:%s\r\n", $key, $value);
            }
        }

        $trailerFrame = $this->encodeFrame(0x80, $trailerString);

        $responseBody = $dataFrame . $trailerFrame;

        if ($requestContentType->isTextEncoded()) {
            $responseBody = base64_encode($responseBody);
        }

        return new GrpcWebResponse(
            body: $responseBody,
            status: $result->status,
            contentType: $requestContentType->responseContentType(),
            httpStatus: $result->status->httpStatusCode(),
        );
    }

    /**
     * Encode a gRPC frame.
     *
     * @param int $flags  Frame flags (0 = data, 0x80 = trailers)
     * @param string $data Frame payload
     */
    private function encodeFrame(int $flags, string $data): string
    {
        return chr($flags & 0xFF) . pack('N', strlen($data)) . $data;
    }

    /**
     * Convert HTTP headers to gRPC metadata format.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, list<string>>
     */
    private function headersToMetadata(array $headers): array
    {
        $metadata = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower($name);

            // Skip HTTP-specific headers that don't map to gRPC metadata
            if (str_starts_with($lower, 'content-') || $lower === 'host' || $lower === 'connection') {
                continue;
            }

            $metadata[$lower] = [$value];
        }

        return $metadata;
    }
}
