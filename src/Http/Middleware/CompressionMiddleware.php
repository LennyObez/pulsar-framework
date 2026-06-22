<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;

use function array_map;
use function explode;
use function extension_loaded;
use function function_exists;
use function gzencode;
use function in_array;
use function is_string;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

/**
 * HTTP response compression middleware.
 *
 * Negotiates encoding via Accept-Encoding and compresses response bodies
 * using gzip, brotli (if ext-brotli available), or zstd (if ext-zstd available).
 * Skips already-compressed content types (images, video, fonts, archives).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CompressionMiddleware implements MiddlewareInterface
{
    private const int MIN_COMPRESS_BYTES = 256;

    /**
     * Content types that should never be compressed (already compressed or binary).
     *
     * @var list<string>
     */
    private const array SKIP_TYPES = [
        'image/',
        'video/',
        'audio/',
        'font/',
        'application/zip',
        'application/gzip',
        'application/x-bzip2',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/woff',
        'application/woff2',
        'application/octet-stream',
    ];

    public function __construct(
        private int $minimumBytes = self::MIN_COMPRESS_BYTES,
        private int $gzipLevel = 5,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Already encoded
        if ($response->hasHeader('Content-Encoding')) {
            return $response;
        }

        // Check content type: skip binary/compressed types
        $contentType = $response->getHeaderLine('Content-Type');

        if ($this->shouldSkipContentType($contentType)) {
            return $response;
        }

        // Read body
        $body = (string) $response->getBody();

        if (strlen($body) < $this->minimumBytes) {
            return $response;
        }

        // Negotiate encoding
        $acceptEncoding = $request->getHeaderLine('Accept-Encoding');
        $encoding = $this->negotiateEncoding($acceptEncoding);

        if ($encoding === null) {
            return $response;
        }

        $compressed = $this->compress($body, $encoding);

        if ($compressed === null) {
            return $response;
        }

        // Only use compressed version if it's actually smaller
        if (strlen($compressed) >= strlen($body)) {
            return $response;
        }

        return $response
            ->withBody(new \Pulsar\Http\Message\StringStream($compressed))
            ->withHeader('Content-Encoding', $encoding)
            ->withHeader('Content-Length', (string) strlen($compressed))
            ->withHeader('Vary', $this->appendVary($response, 'Accept-Encoding'));
    }

    private function shouldSkipContentType(string $contentType): bool
    {
        if ($contentType === '') {
            return false;
        }

        $lower = strtolower($contentType);

        foreach (self::SKIP_TYPES as $skipPrefix) {
            if (str_contains($lower, $skipPrefix)) {
                return true;
            }
        }

        return false;
    }

    private function negotiateEncoding(string $acceptEncoding): ?string
    {
        if ($acceptEncoding === '') {
            return null;
        }

        $accepted = array_map(
            static fn(string $s): string => trim(explode(';', $s, 2)[0]),
            explode(',', strtolower($acceptEncoding)),
        );

        // Prefer brotli > zstd > gzip > deflate
        if (in_array('br', $accepted, true) && extension_loaded('brotli')) {
            return 'br';
        }

        if (in_array('zstd', $accepted, true) && function_exists('zstd_compress')) {
            return 'zstd';
        }

        if (in_array('gzip', $accepted, true)) {
            return 'gzip';
        }

        if (in_array('deflate', $accepted, true)) {
            return 'deflate';
        }

        return null;
    }

    private function compress(string $data, string $encoding): ?string
    {
        return match ($encoding) {
            'gzip' => gzencode($data, $this->gzipLevel) ?: null,
            'deflate' => gzdeflate($data, $this->gzipLevel) ?: null,
            'br' => $this->brotliCompress($data),
            'zstd' => $this->zstdCompress($data),
            default => null,
        };
    }

    private function brotliCompress(string $data): ?string
    {
        if (!function_exists('brotli_compress')) {
            return null;
        }

        $result = brotli_compress($data);

        return is_string($result) ? $result : null;
    }

    private function zstdCompress(string $data): ?string
    {
        if (!function_exists('zstd_compress')) {
            return null;
        }

        $result = zstd_compress($data);

        return is_string($result) ? $result : null;
    }

    /**
     * Append a value to the Vary header without duplicating existing entries.
     */
    private function appendVary(ResponseInterface $response, string $value): string
    {
        $existing = $response->getHeaderLine('Vary');

        if ($existing === '') {
            return $value;
        }

        $parts = array_map('trim', explode(',', $existing));

        if (in_array($value, $parts, true)) {
            return $existing;
        }

        return $existing . ', ' . $value;
    }
}
