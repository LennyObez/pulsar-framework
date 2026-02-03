<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function bin2hex;

use Random\RandomException;

use function random_bytes;
use function sprintf;
use function strtoupper;
use function substr;

/**
 * Generates recovery codes in XXXX-XXXX format.
 */
final readonly class RecoveryCodeGenerator
{
    /**
     * Generate a set of recovery codes.
     *
     * @param int $count Number of codes to generate
     * @return list<string> Recovery codes in XXXX-XXXX format
     *
     * @throws RandomException
     */
    public function generate(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $bytes = random_bytes(4);
            $hex = strtoupper(bin2hex($bytes));
            $codes[] = sprintf('%s-%s', substr($hex, 0, 4), substr($hex, 4, 4));
        }

        return $codes;
    }
}
