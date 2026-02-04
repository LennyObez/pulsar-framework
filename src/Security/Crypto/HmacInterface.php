<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;
use SensitiveParameter;
use SodiumException;

#[Api(since: '1.0.0')]
interface HmacInterface
{
    /**
     * @throws SodiumException
     */
    public function computeHex(#[SensitiveParameter] string $message, #[SensitiveParameter] string $key): string;

    /**
     * @throws SodiumException
     */
    public function verifyHex(#[SensitiveParameter] string $message, string $expectedHex, #[SensitiveParameter] string $key): bool;
}
