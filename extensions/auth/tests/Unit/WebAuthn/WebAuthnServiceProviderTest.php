<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Auth\WebAuthn\Adapter\WebAuthnServer;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\AuthenticatorRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface;
use Pulsar\Extension\Auth\WebAuthn\WebAuthnServiceProvider;

#[CoversClass(WebAuthnServiceProvider::class)]
final class WebAuthnServiceProviderTest extends TestCase
{
    #[Test]
    public function providesContainsAllExpectedBindings(): void
    {
        $provider = new WebAuthnServiceProvider();
        $provides = $provider->provides();

        self::assertContains(WebAuthnConfig::class, $provides);
        self::assertContains(CredentialRepositoryInterface::class, $provides);
        self::assertContains(AuthenticatorRepositoryInterface::class, $provides);
        self::assertContains(AttestationVerifierInterface::class, $provides);
        self::assertContains(RegistrationCeremony::class, $provides);
        self::assertContains(AuthenticationCeremony::class, $provides);
        self::assertContains(WebAuthnServerInterface::class, $provides);
        self::assertContains(WebAuthnServer::class, $provides);
    }

    #[Test]
    public function providesReturnsEightEntries(): void
    {
        $provider = new WebAuthnServiceProvider();

        self::assertCount(8, $provider->provides());
    }

    #[Test]
    public function registerCallsBindForAllServices(): void
    {
        $provider = new WebAuthnServiceProvider();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::atLeast(8))
            ->method('bind');

        $provider->register($container);
    }
}
