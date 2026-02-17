<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Ceremony;

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
    public function constructorSetsAllProperties(): void
    {
        $credential = $this->makeCredential();

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
    public function nonDiscoverableCredential(): void
    {
        $credential = $this->makeCredential();

        $result = new RegistrationResult(
            credential: $credential,
            attestationFormat: 'none',
            isDiscoverable: false,
        );

        self::assertFalse($result->isDiscoverable);
        self::assertSame('none', $result->attestationFormat);
    }

    #[Test]
    public function credentialSourceIsAccessible(): void
    {
        $credential = $this->makeCredential();

        $result = new RegistrationResult(
            credential: $credential,
            attestationFormat: 'tpm',
            isDiscoverable: true,
        );

        self::assertSame('cred-1', $result->credential->credentialId);
        self::assertSame('user-1', $result->credential->userId);
    }

    private function makeCredential(): CredentialSource
    {
        return new CredentialSource(
            credentialId: 'cred-1',
            userId: 'user-1',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----',
            signatureCounter: 0,
            attestationFormat: 'packed',
            transports: ['internal'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );
    }
}
