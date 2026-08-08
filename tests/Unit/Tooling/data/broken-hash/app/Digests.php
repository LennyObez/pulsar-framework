<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Data\BrokenHash\App;

const LEGACY_ALGORITHM = 'md5';

function rawMd5(string $value): string
{
    return md5($value);
}

function rawSha1(string $value): string
{
    return sha1($value);
}

function hashWithSha1(string $value): string
{
    return hash('sha1', $value);
}

function hmacWithMd5(string $value, string $key): string
{
    return hash_hmac('md5', $value, $key);
}

function namedArgumentAlgorithm(string $value): string
{
    return hash(algo: 'md5', data: $value);
}

function uppercaseAlgorithm(string $value): string
{
    return hash('MD5', $value);
}

function algorithmFromConstant(string $value): string
{
    return hash(LEGACY_ALGORITHM, $value);
}

function fileDigest(string $path): string|false
{
    return sha1_file($path);
}
