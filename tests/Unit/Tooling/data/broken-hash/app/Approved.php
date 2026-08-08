<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Data\BrokenHash\Approved;

function sha256(string $value): string
{
    return hash('sha256', $value);
}

function hmacSha256(string $value, string $key): string
{
    return hash_hmac('sha256', $value, $key);
}

/**
 * RFC 6238 mandates HMAC-SHA-1, and HMAC does not rest on collision resistance.
 * ADR-0006 lists this as an approved exception.
 */
function totpHmac(string $counterBytes, string $secret): string
{
    return hash_hmac('sha1', $counterBytes, $secret, true);
}

function algorithmDecidedAtRuntime(string $algorithm, string $value): string
{
    return hash($algorithm, $value);
}

function blake2b(string $value): string
{
    return sodium_crypto_generichash($value, '', 32);
}
