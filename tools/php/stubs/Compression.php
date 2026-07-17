<?php

/**
 * Stub for the optional ext-brotli and ext-zstd compression extensions.
 *
 * Provides type declarations for Psalm static analysis so the result is
 * identical whether or not these extensions are loaded. The real functions
 * are provided by ext-brotli / ext-zstd at runtime; the source always guards
 * usage behind function_exists().
 *
 * Every symbol stubbed here MUST be exercised against the real extension in
 * CI (see the compression jobs in .github/workflows/ci.yml) — a stub with no
 * real counterpart in the matrix is an untested promise, not coverage.
 *
 * Coverage is scoped to the symbols used by:
 *   src/Http/Middleware/CompressionMiddleware.php
 *   src/Cache/Application/Compression/CompressingCacheDecorator.php
 *   their tests (which decompress to assert round-trips)
 */

/**
 * NOTE the $level default of 11: that is the extension's own, and it is a trap
 * for dynamic content (~86x gzip-5). Callers on a request path must pass an
 * explicit quality — see CompressionMiddleware::BROTLI_DYNAMIC_QUALITY.
 */
function brotli_compress(string $data, int $level = 11, int $mode = 0): string|false
{
    return false;
}

function brotli_uncompress(string $data): string|false
{
    return false;
}

function zstd_compress(string $data, int $level = 3): string|false
{
    return false;
}

function zstd_uncompress(string $data): string|false
{
    return false;
}
