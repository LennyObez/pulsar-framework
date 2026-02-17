<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\WebAuthn\Adapter\WebAuthnServer;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Contract\AttestationVerifierInterface;

#[CoversClass(WebAuthnServer::class)]
final class WebAuthnServerTest extends TestCase
{
    private WebAuthnServer $server;

    protected function setUp(): void
    {
        $config = WebAuthnConfig::fromArray([
            'rp_name' => 'Test RP',
            'rp_id' => 'localhost',
            'origin' => 'https://localhost',
        ]);

        $credentialRepo = new InMemoryCredentialRepository();
        $attestationVerifier = $this->createStub(AttestationVerifierInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $registrationCeremony = new RegistrationCeremony(
            $config,
            $attestationVerifier,
            $credentialRepo,
            $auditLogger,
        );

        $authenticationCeremony = new AuthenticationCeremony(
            $config,
            $credentialRepo,
            $auditLogger,
        );

        $this->server = new WebAuthnServer($registrationCeremony, $authenticationCeremony);
    }

    #[Test]
    public function generateRegistrationOptionsReturnsOptionsWithChallenge(): void
    {
        $options = $this->server->generateRegistrationOptions('user-1', 'Alice');

        self::assertNotEmpty($options->challenge);
        self::assertNotEmpty($options->publicKeyOptions);
    }

    #[Test]
    public function generateRegistrationOptionsIncludesRpInfo(): void
    {
        $options = $this->server->generateRegistrationOptions('user-1', 'Alice');
        $pkOptions = $options->publicKeyOptions;

        self::assertArrayHasKey('rp', $pkOptions);
        self::assertSame('Test RP', $pkOptions['rp']['name']);
    }

    #[Test]
    public function generateAuthenticationOptionsReturnsOptionsWithChallenge(): void
    {
        $options = $this->server->generateAuthenticationOptions();

        self::assertNotEmpty($options->challenge);
        self::assertNotEmpty($options->publicKeyOptions);
    }

    #[Test]
    public function generateAuthenticationOptionsWithUnknownUserIdThrows(): void
    {
        $this->expectException(\Pulsar\Extension\WebAuthn\Exception\WebAuthnException::class);

        // No credentials registered for this user, so authentication should fail
        $this->server->generateAuthenticationOptions('unknown-user');
    }

    #[Test]
    public function toArrayOnRegistrationOptionsReturnsPublicKeyOptions(): void
    {
        $options = $this->server->generateRegistrationOptions('user-1', 'Bob');

        self::assertSame($options->publicKeyOptions, $options->toArray());
    }

    #[Test]
    public function toArrayOnAuthenticationOptionsReturnsPublicKeyOptions(): void
    {
        $options = $this->server->generateAuthenticationOptions();

        self::assertSame($options->publicKeyOptions, $options->toArray());
    }
}
