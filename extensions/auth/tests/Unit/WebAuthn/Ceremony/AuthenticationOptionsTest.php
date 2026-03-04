<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Ceremony;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationOptions;

#[CoversClass(AuthenticationOptions::class)]
final class AuthenticationOptionsTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $publicKeyOptions = [
            'challenge' => 'abc123',
            'rpId' => 'example.com',
            'timeout' => 60000,
        ];

        $options = new AuthenticationOptions(
            challenge: 'abc123',
            publicKeyOptions: $publicKeyOptions,
        );

        self::assertSame('abc123', $options->challenge);
        self::assertSame($publicKeyOptions, $options->publicKeyOptions);
    }

    #[Test]
    public function toArrayReturnsPublicKeyOptions(): void
    {
        $publicKeyOptions = [
            'challenge' => 'def456',
            'rpId' => 'test.com',
            'allowCredentials' => [['type' => 'public-key', 'id' => 'cred-1']],
        ];

        $options = new AuthenticationOptions(
            challenge: 'def456',
            publicKeyOptions: $publicKeyOptions,
        );

        self::assertSame($publicKeyOptions, $options->toArray());
    }

    #[Test]
    public function toArrayWithEmptyOptions(): void
    {
        $options = new AuthenticationOptions(
            challenge: 'test',
            publicKeyOptions: [],
        );

        self::assertSame([], $options->toArray());
    }
}
