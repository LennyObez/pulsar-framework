<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivacyPassDirectory;

use function json_encode;

#[CoversClass(PrivacyPassDirectory::class)]
final class PrivacyPassDirectoryTest extends TestCase
{
    #[Test]
    public function extractsBlindRsaKeysInOrder(): void
    {
        $json = (string) json_encode([
            'token-keys' => [
                ['token-type' => 2, 'token-key' => 'keyA'],
                ['token-type' => 2, 'token-key' => 'keyB'],
            ],
        ]);

        self::assertSame(['keyA', 'keyB'], PrivacyPassDirectory::parseKeys($json));
    }

    #[Test]
    public function ignoresNonBlindRsaTokenTypes(): void
    {
        $json = (string) json_encode([
            'token-keys' => [
                ['token-type' => 1, 'token-key' => 'voprfKey'],
                ['token-type' => 2, 'token-key' => 'blindRsaKey'],
                ['token-type' => 5, 'token-key' => 'otherKey'],
            ],
        ]);

        self::assertSame(['blindRsaKey'], PrivacyPassDirectory::parseKeys($json));
    }

    #[Test]
    public function acceptsTokenTypeAsAString(): void
    {
        $json = '{"token-keys":[{"token-type":"2","token-key":"stringTypeKey"}]}';

        self::assertSame(['stringTypeKey'], PrivacyPassDirectory::parseKeys($json));
    }

    #[Test]
    public function deduplicatesKeys(): void
    {
        $json = (string) json_encode([
            'token-keys' => [
                ['token-type' => 2, 'token-key' => 'dup'],
                ['token-type' => 2, 'token-key' => 'dup'],
                ['token-type' => 2, 'token-key' => 'other'],
            ],
        ]);

        self::assertSame(['dup', 'other'], PrivacyPassDirectory::parseKeys($json));
    }

    #[Test]
    public function returnsEmptyForMalformedOrEmptyInput(): void
    {
        self::assertSame([], PrivacyPassDirectory::parseKeys('not json'));
        self::assertSame([], PrivacyPassDirectory::parseKeys('"a string"'));
        self::assertSame([], PrivacyPassDirectory::parseKeys('{}'));
        self::assertSame([], PrivacyPassDirectory::parseKeys('{"token-keys":"nope"}'));
        self::assertSame([], PrivacyPassDirectory::parseKeys('{"token-keys":[{"token-type":2}]}'), 'no token-key');
        self::assertSame([], PrivacyPassDirectory::parseKeys('{"token-keys":[{"token-type":2,"token-key":""}]}'), 'empty key');
    }
}
