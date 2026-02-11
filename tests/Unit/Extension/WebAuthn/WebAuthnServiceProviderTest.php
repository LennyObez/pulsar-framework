<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\WebAuthn\Adapter\WebAuthnServer;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\WebAuthn\Contract\AuthenticatorRepositoryInterface;
use Pulsar\Extension\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\WebAuthn\Contract\WebAuthnServerInterface;
use Pulsar\Extension\WebAuthn\WebAuthnServiceProvider;
use Pulsar\Security\Audit\AuditEntry;

use function is_callable;

#[CoversClass(WebAuthnServiceProvider::class)]
final class WebAuthnServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsAllContracts(): void
    {
        $bindings = [];
        $resolved = [];

        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === 'config.webauthn',
        );
        $container->method('bind')->willReturnCallback(
            static function (string $abstract, mixed $concrete) use (&$bindings): void {
                $bindings[$abstract] = $concrete;
            },
        );
        $container->method('get')->willReturnCallback(
            static function (string $id) use (&$bindings, &$resolved, $auditLogger): mixed {
                if ($id === 'config.webauthn') {
                    return ['rp_name' => 'Test RP', 'rp_id' => 'example.com', 'origin' => 'https://example.com'];
                }
                if ($id === AuditLoggerInterface::class) {
                    return $auditLogger;
                }
                if (isset($bindings[$id])) {
                    $factory = $bindings[$id];
                    if (is_callable($factory)) {
                        if (!isset($resolved[$id])) {
                            $resolved[$id] = $factory();
                        }
                        return $resolved[$id];
                    }
                }
                return null;
            },
        );

        $provider = new WebAuthnServiceProvider();
        $provider->register($container);

        self::assertArrayHasKey(WebAuthnConfig::class, $bindings);
        self::assertArrayHasKey(CredentialRepositoryInterface::class, $bindings);
        self::assertArrayHasKey(AuthenticatorRepositoryInterface::class, $bindings);
        self::assertArrayHasKey(AttestationVerifierInterface::class, $bindings);
        self::assertArrayHasKey(RegistrationCeremony::class, $bindings);
        self::assertArrayHasKey(AuthenticationCeremony::class, $bindings);
        self::assertArrayHasKey(WebAuthnServerInterface::class, $bindings);
        self::assertArrayHasKey(WebAuthnServer::class, $bindings);
    }

    #[Test]
    public function providesReturnsAllBoundClasses(): void
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
    public function registerCreatesConfigFromContainerData(): void
    {
        $bindings = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('bind')->willReturnCallback(
            static function (string $abstract, mixed $concrete) use (&$bindings): void {
                $bindings[$abstract] = $concrete;
            },
        );
        $container->method('get')->willReturnCallback(
            static function (string $id): mixed {
                if ($id === 'config.webauthn') {
                    return ['rp_name' => 'My App', 'rp_id' => 'myapp.com', 'origin' => 'https://myapp.com'];
                }
                return null;
            },
        );

        $provider = new WebAuthnServiceProvider();
        $provider->register($container);

        $configFactory = $bindings[WebAuthnConfig::class];
        self::assertIsCallable($configFactory);
        /** @var WebAuthnConfig $config */
        $config = $configFactory();

        self::assertSame('My App', $config->rpName);
        self::assertSame('myapp.com', $config->rpId);
    }

    #[Test]
    public function registerCreatesDefaultConfigWhenNoConfigExists(): void
    {
        $bindings = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('bind')->willReturnCallback(
            static function (string $abstract, mixed $concrete) use (&$bindings): void {
                $bindings[$abstract] = $concrete;
            },
        );

        $provider = new WebAuthnServiceProvider();
        $provider->register($container);

        $configFactory = $bindings[WebAuthnConfig::class];
        self::assertIsCallable($configFactory);
        /** @var WebAuthnConfig $config */
        $config = $configFactory();

        self::assertSame('', $config->rpName);
        self::assertSame('', $config->rpId);
    }
}
