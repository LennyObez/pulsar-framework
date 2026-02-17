<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\PublicKey;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

#[CoversClass(CredentialSource::class)]
final class CredentialSourceTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01');

        $source = new CredentialSource(
            credentialId: 'cred-1',
            userId: 'user-1',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\nMFk...\n-----END PUBLIC KEY-----',
            signatureCounter: 5,
            attestationFormat: 'packed',
            transports: ['usb', 'nfc'],
            discoverable: true,
            aaguid: 'f8a011f3-8c0a-4d15-8006-17111f9edc7d',
            createdAt: $createdAt,
            algorithmId: -7,
        );

        self::assertSame('cred-1', $source->credentialId);
        self::assertSame('user-1', $source->userId);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $source->publicKeyPem);
        self::assertSame(5, $source->signatureCounter);
        self::assertSame('packed', $source->attestationFormat);
        self::assertSame(['usb', 'nfc'], $source->transports);
        self::assertTrue($source->discoverable);
        self::assertSame('f8a011f3-8c0a-4d15-8006-17111f9edc7d', $source->aaguid);
        self::assertSame($createdAt, $source->createdAt);
        self::assertSame(-7, $source->algorithmId);
    }

    #[Test]
    public function algorithmIdDefaultsToEs256(): void
    {
        $source = new CredentialSource(
            credentialId: 'cred-2',
            userId: 'user-2',
            publicKeyPem: 'pem-data',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: false,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame(-7, $source->algorithmId);
    }

    #[Test]
    public function rs256AlgorithmId(): void
    {
        $source = new CredentialSource(
            credentialId: 'cred-3',
            userId: 'user-3',
            publicKeyPem: 'rsa-pem-data',
            signatureCounter: 0,
            attestationFormat: 'packed',
            transports: ['usb'],
            discoverable: false,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
            algorithmId: -257,
        );

        self::assertSame(-257, $source->algorithmId);
    }

    #[Test]
    public function emptyTransportsList(): void
    {
        $source = new CredentialSource(
            credentialId: 'cred-4',
            userId: 'user-4',
            publicKeyPem: 'pem',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: [],
            discoverable: false,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame([], $source->transports);
    }

    #[Test]
    public function multipleTransports(): void
    {
        $source = new CredentialSource(
            credentialId: 'cred-5',
            userId: 'user-5',
            publicKeyPem: 'pem',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['usb', 'nfc', 'ble', 'internal'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );

        self::assertCount(4, $source->transports);
        self::assertContains('ble', $source->transports);
    }
}
