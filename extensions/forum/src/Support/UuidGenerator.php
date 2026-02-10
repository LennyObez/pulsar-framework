<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Support;

use Pulsar\Api\Internal;

use function bin2hex;
use function dechex;
use function hexdec;
use function microtime;
use function random_bytes;
use function sprintf;
use function str_pad;
use function substr;

use const STR_PAD_LEFT;

#[Internal(reason: 'UUID generation utility — implementation detail')]
final class UuidGenerator
{
    public static function v7(): string
    {
        $timestamp = microtime(true);
        $time = (int) ($timestamp * 1000.0);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }
}
