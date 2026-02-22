<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Web;

use Pulsar\Api\Api;

use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Content types supported by the gRPC-Web adapter.
 */
#[Api(since: '1.0.0')]
enum GrpcWebContentType: string
{
    case GrpcWeb = 'application/grpc-web';
    case GrpcWebProto = 'application/grpc-web+proto';
    case GrpcWebText = 'application/grpc-web-text';

    /**
     * Whether the given content type is a supported gRPC-Web content type.
     */
    public static function isSupported(string $contentType): bool
    {
        $normalized = strtolower(trim($contentType));

        return self::tryFrom($normalized) !== null
            || str_starts_with($normalized, 'application/grpc-web');
    }

    /**
     * Resolve from a raw content type header value.
     */
    public static function fromHeader(string $contentType): ?self
    {
        $normalized = strtolower(trim($contentType));

        // Exact match first
        $result = self::tryFrom($normalized);

        if ($result !== null) {
            return $result;
        }

        // Check for content type with parameters (e.g., "application/grpc-web+proto; charset=utf-8")
        $base = strstr($normalized, ';', true);

        if ($base !== false) {
            return self::tryFrom(trim($base));
        }

        return null;
    }

    /**
     * Whether this content type uses base64-encoded payloads.
     */
    public function isTextEncoded(): bool
    {
        return $this === self::GrpcWebText;
    }

    /**
     * The response content type to use for this request content type.
     */
    public function responseContentType(): string
    {
        return match ($this) {
            self::GrpcWebText => 'application/grpc-web-text',
            default => 'application/grpc-web+proto',
        };
    }
}
