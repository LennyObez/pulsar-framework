<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Internal;
use SensitiveParameter;
use SodiumException;

#[Internal]
final readonly class HmacService implements HmacInterface
{
    /**
     * @throws SodiumException
     */
    public function computeHex(#[SensitiveParameter] string $message, #[SensitiveParameter] string $key): string
    {
        return Hmac::computeHex($message, $key);
    }

    /**
     * @throws SodiumException
     */
    public function verifyHex(#[SensitiveParameter] string $message, string $expectedHex, #[SensitiveParameter] string $key): bool
    {
        return Hmac::verifyHex($message, $expectedHex, $key);
    }
}
