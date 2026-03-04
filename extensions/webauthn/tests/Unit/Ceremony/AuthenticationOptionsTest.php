<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Ceremony;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationOptions;

final class AuthenticationOptionsTest extends TestCase
{
    #[Test]
    public function constructionStoresProperties(): void
    {
        $options = new AuthenticationOptions(
            challenge: 'base64-challenge',
            publicKeyOptions: [
                'rpId' => 'example.com',
                'challenge' => 'base64-challenge',
                'allowCredentials' => [],
                'timeout' => 60000,
                'userVerification' => 'preferred',
            ],
        );

        self::assertSame('base64-challenge', $options->challenge);
        self::assertSame('example.com', $options->publicKeyOptions['rpId']);
        self::assertSame(60000, $options->publicKeyOptions['timeout']);
    }

    #[Test]
    public function toArrayReturnsPublicKeyOptions(): void
    {
        $publicKeyOptions = [
            'rpId' => 'test.com',
            'challenge' => 'abc123',
            'allowCredentials' => [
                ['id' => 'cred-1', 'type' => 'public-key'],
            ],
            'timeout' => 30000,
        ];

        $options = new AuthenticationOptions(
            challenge: 'abc123',
            publicKeyOptions: $publicKeyOptions,
        );

        self::assertSame($publicKeyOptions, $options->toArray());
    }

    #[Test]
    public function toArrayWithEmptyOptions(): void
    {
        $options = new AuthenticationOptions(challenge: 'challenge', publicKeyOptions: []);

        self::assertSame([], $options->toArray());
    }
}
