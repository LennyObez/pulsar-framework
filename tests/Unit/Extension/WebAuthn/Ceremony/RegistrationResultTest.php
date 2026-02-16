<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Ceremony;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationResult;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

#[CoversClass(RegistrationResult::class)]
final class RegistrationResultTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $credential = new CredentialSource(
            credentialId: 'cred-001',
            userId: 'user-42',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----',
            signatureCounter: 0,
            attestationFormat: 'packed',
            transports: ['internal'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );

        $result = new RegistrationResult(
            credential: $credential,
            attestationFormat: 'packed',
            isDiscoverable: true,
        );

        self::assertSame($credential, $result->credential);
        self::assertSame('packed', $result->attestationFormat);
        self::assertTrue($result->isDiscoverable);
    }

    #[Test]
    public function nonDiscoverableRegistration(): void
    {
        $credential = new CredentialSource(
            credentialId: 'cred-002',
            userId: 'user-42',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['usb'],
            discoverable: false,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );

        $result = new RegistrationResult(
            credential: $credential,
            attestationFormat: 'none',
            isDiscoverable: false,
        );

        self::assertFalse($result->isDiscoverable);
        self::assertSame('none', $result->attestationFormat);
    }
}
