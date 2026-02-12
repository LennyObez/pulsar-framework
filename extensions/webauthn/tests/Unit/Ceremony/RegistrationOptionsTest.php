<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Ceremony;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationResult;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationResult;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

final class RegistrationOptionsTest extends TestCase
{
    #[Test]
    public function registration_options_construction(): void
    {
        $options = new RegistrationOptions(
            challenge: 'Y2hhbGxlbmdl',
            publicKeyOptions: ['rp' => ['name' => 'Test'], 'challenge' => 'Y2hhbGxlbmdl'],
        );

        self::assertSame('Y2hhbGxlbmdl', $options->challenge);
        self::assertSame(['rp' => ['name' => 'Test'], 'challenge' => 'Y2hhbGxlbmdl'], $options->publicKeyOptions);
    }

    #[Test]
    public function registration_options_to_array_returns_public_key_options(): void
    {
        $publicKeyOptions = ['rp' => ['name' => 'Test'], 'timeout' => 60000];
        $options = new RegistrationOptions(challenge: 'abc', publicKeyOptions: $publicKeyOptions);

        self::assertSame($publicKeyOptions, $options->toArray());
    }

    #[Test]
    public function authentication_options_construction(): void
    {
        $options = new AuthenticationOptions(
            challenge: 'auth-challenge',
            publicKeyOptions: ['rpId' => 'example.com'],
        );

        self::assertSame('auth-challenge', $options->challenge);
    }

    #[Test]
    public function authentication_options_to_array(): void
    {
        $pkOptions = ['rpId' => 'example.com', 'timeout' => 60000];
        $options = new AuthenticationOptions(challenge: 'c', publicKeyOptions: $pkOptions);

        self::assertSame($pkOptions, $options->toArray());
    }

    #[Test]
    public function authentication_result_construction(): void
    {
        $result = new AuthenticationResult(
            credentialId: 'cred-1',
            userId: 'user-1',
            signatureCounter: 42,
            userVerified: true,
        );

        self::assertSame('cred-1', $result->credentialId);
        self::assertSame('user-1', $result->userId);
        self::assertSame(42, $result->signatureCounter);
        self::assertTrue($result->userVerified);
    }

    #[Test]
    public function registration_result_construction(): void
    {
        $credential = new CredentialSource(
            credentialId: 'cred-1',
            userId: 'user-1',
            publicKeyPem: 'pem',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: '0000',
            createdAt: new DateTimeImmutable(),
        );

        $result = new RegistrationResult(
            credential: $credential,
            attestationFormat: 'none',
            isDiscoverable: true,
        );

        self::assertSame($credential, $result->credential);
        self::assertSame('none', $result->attestationFormat);
        self::assertTrue($result->isDiscoverable);
    }
}
