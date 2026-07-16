<?php

/**
 * Stub for the optional ext-brotli, ext-zstd, and ext-lz4 compression
 * extensions.
 *
 * Provides type declarations for Psalm static analysis so the result is
 * identical whether or not these extensions are loaded. The real functions
 * are provided by ext-brotli / ext-zstd / ext-lz4 at runtime; the source
 * always guards usage behind function_exists().
 *
 * Coverage is scoped to the symbols used by:
 *   src/Http/Middleware/CompressionMiddleware.php
 *   src/Cache/Application/Compression/CompressingCacheDecorator.php
 */

function brotli_compress(string $data, int $level = 11, int $mode = 0): string|false
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

function lz4_compress(string $data, int $level = 0): string|false
{
    return false;
}

function lz4_uncompress(string $data): string|false
{
    return false;
}
