<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationResult;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationResult;
use Pulsar\Extension\WebAuthn\Contract\WebAuthnServerInterface;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

#[CoversClass(WebAuthnServerInterface::class)]
final class WebAuthnServerInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanGenerateRegistrationOptions(): void
    {
        $options = new RegistrationOptions(
            challenge: 'base64-challenge-string',
            publicKeyOptions: [
                'rp' => ['name' => 'Test App'],
                'user' => ['id' => 'user-1', 'name' => 'alice', 'displayName' => 'Alice'],
            ],
        );

        $stub = $this->createStub(WebAuthnServerInterface::class);
        $stub->method('generateRegistrationOptions')->willReturn($options);

        $result = $stub->generateRegistrationOptions('user-1', 'Alice');

        self::assertSame('base64-challenge-string', $result->challenge);
        self::assertArrayHasKey('rp', $result->publicKeyOptions);
        self::assertArrayHasKey('user', $result->publicKeyOptions);
    }

    #[Test]
    public function stubCanGenerateRegistrationOptionsWithExclusions(): void
    {
        $options = new RegistrationOptions(
            challenge: 'challenge-2',
            publicKeyOptions: ['excludeCredentials' => [['id' => 'existing-cred']]],
        );

        $stub = $this->createStub(WebAuthnServerInterface::class);
        $stub->method('generateRegistrationOptions')->willReturn($options);

        $result = $stub->generateRegistrationOptions('user-1', 'Alice', ['existing-cred']);

        self::assertArrayHasKey('excludeCredentials', $result->toArray());
    }

    #[Test]
    public function stubCanVerifyRegistration(): void
    {
        $credential = new CredentialSource(
            credentialId: 'new-cred-1',
            userId: 'user-1',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\ntest\n-----END PUBLIC KEY-----',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable('2026-03-15T10:00:00+00:00'),
        );

        $registrationResult = new RegistrationResult(
            credential: $credential,
            attestationFormat: 'none',
            isDiscoverable: true,
        );

        $stub = $this->createStub(WebAuthnServerInterface::class);
        $stub->method('verifyRegistration')->willReturn($registrationResult);

        $result = $stub->verifyRegistration('{"response":"data"}', 'challenge-1');

        self::assertSame('new-cred-1', $result->credential->credentialId);
        self::assertSame('none', $result->attestationFormat);
        self::assertTrue($result->isDiscoverable);
    }

    #[Test]
    public function stubCanGenerateAuthenticationOptions(): void
    {
        $options = new AuthenticationOptions(
            challenge: 'auth-challenge',
            publicKeyOptions: [
                'rpId' => 'example.com',
                'allowCredentials' => [],
                'userVerification' => 'preferred',
            ],
        );

        $stub = $this->createStub(WebAuthnServerInterface::class);
        $stub->method('generateAuthenticationOptions')->willReturn($options);

        $result = $stub->generateAuthenticationOptions('user-1');

        self::assertSame('auth-challenge', $result->challenge);
        self::assertArrayHasKey('rpId', $result->toArray());
    }

    #[Test]
    public function stubCanGenerateDiscoverableAuthenticationOptions(): void
    {
        $options = new AuthenticationOptions(
            challenge: 'passkey-challenge',
            publicKeyOptions: ['userVerification' => 'required'],
        );

        $stub = $this->createStub(WebAuthnServerInterface::class);
        $stub->method('generateAuthenticationOptions')->willReturn($options);

        // null userId for discoverable/passkey flow
        $result = $stub->generateAuthenticationOptions(null);

        self::assertSame('passkey-challenge', $result->challenge);
    }

    #[Test]
    public function stubCanVerifyAuthentication(): void
    {
        $authResult = new AuthenticationResult(
            credentialId: 'cred-1',
            userId: 'user-1',
            signatureCounter: 6,
            userVerified: true,
        );

        $stub = $this->createStub(WebAuthnServerInterface::class);
        $stub->method('verifyAuthentication')->willReturn($authResult);

        $result = $stub->verifyAuthentication('{"assertion":"data"}', 'auth-challenge', 'user-1');

        self::assertSame('cred-1', $result->credentialId);
        self::assertSame('user-1', $result->userId);
        self::assertSame(6, $result->signatureCounter);
        self::assertTrue($result->userVerified);
    }

    #[Test]
    public function stubCanVerifyDiscoverableAuthentication(): void
    {
        $authResult = new AuthenticationResult(
            credentialId: 'cred-discoverable',
            userId: 'discovered-user',
            signatureCounter: 1,
            userVerified: true,
        );

        $stub = $this->createStub(WebAuthnServerInterface::class);
        $stub->method('verifyAuthentication')->willReturn($authResult);

        // null expectedUserId for discoverable flow
        $result = $stub->verifyAuthentication('{"assertion":"data"}', 'challenge', null);

        self::assertSame('discovered-user', $result->userId);
    }
}
