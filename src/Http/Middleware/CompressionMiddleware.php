<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\ContentNegotiation;

use function array_map;
use function explode;
use function function_exists;
use function gzcompress;
use function gzencode;
use function in_array;
use function is_string;
use function str_contains;
use function strlen;
use function strtolower;

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
     * Brotli quality for on-the-fly response compression.
     *
     * Deliberately NOT the extension's own default (11). Quality 11 targets
     * build-time pre-compression of static assets — compress once, serve a
     * million times — and is unusable for dynamic responses: measured on
     * PHP 8.5 with libbrotli, q11 costs 105ms of CPU on a 51KB page and 21ms on
     * a 9KB page, roughly 86x gzip-5, to save 16-20% of bytes. Calling
     * brotli_compress() without this argument silently opts every dynamic
     * response into that.
     *
     * q5 is the measured optimum for dynamic text: it beats gzip-5 on ratio
     * (-7.8% on a 9KB page) at comparable cost, and dominates its neighbours —
     * q4 is both slower and larger, q6 is 1.8x slower for 0.2% fewer bytes.
     */
    private const int BROTLI_DYNAMIC_QUALITY = 5;

    /**
     * Zstd level for on-the-fly response compression; the extension's own
     * default, restated here so the level is explicit and tunable rather than
     * inherited invisibly.
     */
    private const int ZSTD_DYNAMIC_LEVEL = 3;

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
        private int $brotliQuality = self::BROTLI_DYNAMIC_QUALITY,
        private int $zstdLevel = self::ZSTD_DYNAMIC_LEVEL,
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

        // Negotiate encoding (quality-aware: honors q-values and q=0 refusals)
        $encoding = $this->negotiateEncoding($request);

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

    private function negotiateEncoding(ServerRequestInterface $request): ?string
    {
        // No client preference expressed: do not compress (conservative default).
        if ($request->getHeaderLine('Accept-Encoding') === '') {
            return null;
        }

        // ContentNegotiation honors the client's q-values and treats q=0 as an
        // explicit refusal (RFC 9110), unlike the previous token-only parse which
        // discarded parameters and selected gzip even when the client sent
        // "gzip;q=0".
        return ContentNegotiation::negotiateEncoding($request, $this->availableEncodings());
    }

    /**
     * Encodings this server can actually produce right now, in server preference
     * order (brotli > zstd > gzip > deflate).
     *
     * The capability test for each optional codec is the very function compress()
     * calls (brotli_compress / zstd_compress), so negotiation never offers an
     * encoding the compressor cannot deliver — and conversely, whenever a codec
     * extension is installed it is both negotiated and used. (gzip and deflate
     * are always available through ext-zlib, which ships with PHP.)
     *
     * @return list<string>
     */
    private function availableEncodings(): array
    {
        $available = [];

        if (function_exists('brotli_compress')) {
            $available[] = 'br';
        }

        if (function_exists('zstd_compress')) {
            $available[] = 'zstd';
        }

        $available[] = 'gzip';
        $available[] = 'deflate';

        return $available;
    }

    private function compress(string $data, string $encoding): ?string
    {
        return match ($encoding) {
            'gzip' => gzencode($data, $this->gzipLevel) ?: null,
            // zlib-wrapped DEFLATE (RFC 1950), as RFC 9110 requires for
            // Content-Encoding: deflate — not raw DEFLATE (gzdeflate, RFC 1951).
            'deflate' => gzcompress($data, $this->gzipLevel) ?: null,
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

        /** @var string|false $result */
        $result = brotli_compress($data, $this->brotliQuality);

        return is_string($result) ? $result : null;
    }

    private function zstdCompress(string $data): ?string
    {
        if (!function_exists('zstd_compress')) {
            return null;
        }

        /** @var string|false $result */
        $result = zstd_compress($data, $this->zstdLevel);

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
