<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\PublicKey;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;

#[CoversClass(CredentialSource::class)]
final class CredentialSourceTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $credential = new CredentialSource(
            credentialId: 'cred-001',
            userId: 'user-42',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE...\n-----END PUBLIC KEY-----',
            signatureCounter: 0,
            attestationFormat: 'packed',
            transports: ['internal', 'hybrid'],
            discoverable: true,
            aaguid: 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
            createdAt: $createdAt,
        );

        self::assertSame('cred-001', $credential->credentialId);
        self::assertSame('user-42', $credential->userId);
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $credential->publicKeyPem);
        self::assertSame(0, $credential->signatureCounter);
        self::assertSame('packed', $credential->attestationFormat);
        self::assertSame(['internal', 'hybrid'], $credential->transports);
        self::assertTrue($credential->discoverable);
        self::assertSame('fbfc3007-154e-4ecc-8c0b-6e020557d7bd', $credential->aaguid);
        self::assertSame($createdAt, $credential->createdAt);
    }

    #[Test]
    public function emptyTransportsList(): void
    {
        $credential = new CredentialSource(
            credentialId: 'cred-002',
            userId: 'user-42',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\ntest\n-----END PUBLIC KEY-----',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: [],
            discoverable: false,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame([], $credential->transports);
    }

    #[Test]
    public function multipleTransports(): void
    {
        $credential = new CredentialSource(
            credentialId: 'cred-003',
            userId: 'user-42',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\ntest\n-----END PUBLIC KEY-----',
            signatureCounter: 42,
            attestationFormat: 'packed',
            transports: ['usb', 'nfc', 'ble', 'internal'],
            discoverable: false,
            aaguid: 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
            createdAt: new DateTimeImmutable(),
        );

        self::assertCount(4, $credential->transports);
        self::assertContains('usb', $credential->transports);
        self::assertContains('nfc', $credential->transports);
        self::assertContains('ble', $credential->transports);
        self::assertContains('internal', $credential->transports);
    }

    #[Test]
    public function nonDiscoverableCredential(): void
    {
        $credential = new CredentialSource(
            credentialId: 'cred-004',
            userId: 'user-42',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\ntest\n-----END PUBLIC KEY-----',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['usb'],
            discoverable: false,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );

        self::assertFalse($credential->discoverable);
    }
}
