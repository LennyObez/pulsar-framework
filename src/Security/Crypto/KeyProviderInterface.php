<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;
use SodiumException;

#[Api(since: '1.0.0')]
interface KeyProviderInterface
{
    /**
     * @throws SodiumException
     */
    public function deriveSubKey(int $subKeyId, string $context, int $length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES): string;

    /**
     * @throws SodiumException
     */
    public function deriveSubKeyHex(int $subKeyId, string $context, int $length = SODIUM_CRYPTO_SECRETBOX_KEYBYTES): string;
}
