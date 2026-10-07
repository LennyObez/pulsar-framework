<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Ceremony;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationOptions;

#[CoversClass(RegistrationOptions::class)]
final class RegistrationOptionsTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $publicKeyOptions = [
            'rp' => ['name' => 'Test', 'id' => 'test.com'],
            'challenge' => 'xyz789',
        ];

        $options = new RegistrationOptions(
            challenge: 'xyz789',
            publicKeyOptions: $publicKeyOptions,
        );

        self::assertSame('xyz789', $options->challenge);
        self::assertSame($publicKeyOptions, $options->publicKeyOptions);
    }

    #[Test]
    public function toArrayReturnsPublicKeyOptions(): void
    {
        $publicKeyOptions = [
            'rp' => ['name' => 'My App', 'id' => 'myapp.com'],
            'user' => ['id' => 'user-1', 'name' => 'John'],
            'challenge' => 'abc',
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
        ];

        $options = new RegistrationOptions(
            challenge: 'abc',
            publicKeyOptions: $publicKeyOptions,
        );

        self::assertSame($publicKeyOptions, $options->toArray());
    }
}
