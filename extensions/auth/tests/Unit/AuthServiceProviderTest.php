<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Container\Container;
use Pulsar\Extension\Auth\AuthServiceProvider;
use Pulsar\Extension\Auth\Config\AuthConfig;
use Pulsar\Extension\Auth\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationServerInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ClientRepositoryInterface;
use Pulsar\Extension\Auth\Social\Config\SocialSsoConfig;
use Pulsar\Extension\Auth\Social\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\Auth\Social\Contracts\SsoGatewayInterface;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AuthenticatorRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface;
use RuntimeException;

use function count;

#[CoversClass(AuthServiceProvider::class)]
final class AuthServiceProviderTest extends TestCase
{
    #[Test]
    public function provides_includes_all_three_module_services(): void
    {
        $provider = new AuthServiceProvider();
        $provides = $provider->provides();

        // Config classes
        self::assertContains(AuthConfig::class, $provides);
        self::assertContains(SocialSsoConfig::class, $provides);
        self::assertContains(OAuth2Config::class, $provides);
        self::assertContains(WebAuthnConfig::class, $provides);

        // Social SSO
        self::assertContains(OAuthProviderRegistryInterface::class, $provides);
        self::assertContains(IdTokenVerifierInterface::class, $provides);
        self::assertContains(SsoGatewayInterface::class, $provides);

        // OAuth2
        self::assertContains(ClientRepositoryInterface::class, $provides);
        self::assertContains(AccessTokenRepositoryInterface::class, $provides);
        self::assertContains(AuthorizationServerInterface::class, $provides);

        // WebAuthn
        self::assertContains(CredentialRepositoryInterface::class, $provides);
        self::assertContains(WebAuthnServerInterface::class, $provides);
    }

    #[Test]
    public function provides_count_covers_all_bindings(): void
    {
        $provider = new AuthServiceProvider();
        $provides = $provider->provides();

        // Should have a comprehensive list (5 configs + 10 social + 16 oauth2 + 8 webauthn = 39)
        self::assertGreaterThanOrEqual(30, count($provides));
    }

    // --- WebAuthn fails closed without an audit logger ---

    /**
     * WebAuthn deliberately does not degrade to a null audit logger the way the
     * mail, notification, workflow and data-purge wirings in src/Core/Wiring do.
     * Those lose a record; this one would lose the record of who proved
     * possession of a second factor. The refusal has to be explicit and has to
     * name what is missing — a bare container get() fails too, but only with an
     * opaque "no binding found", which reads like an oversight rather than a
     * decision and invites the next reader to "fix" it with a null logger.
     *
     * @return list<array{string, string}>
     */
    public static function webAuthnServicesRequiringAudit(): array
    {
        return [
            'registration ceremony' => [RegistrationCeremony::class, 'registration ceremony'],
            'authentication ceremony' => [AuthenticationCeremony::class, 'authentication ceremony'],
            'authenticator repository' => [AuthenticatorRepositoryInterface::class, 'authenticator repository'],
        ];
    }

    #[Test]
    #[DataProvider('webAuthnServicesRequiringAudit')]
    public function webAuthnServicesRefuseToResolveWithoutAnAuditLogger(string $id, string $subsystem): void
    {
        $container = new Container();
        new AuthServiceProvider()->register($container);

        self::assertFalse($container->has(AuditLoggerInterface::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('WebAuthn ' . $subsystem . ' cannot be built');

        (void) $container->get($id);
    }

    #[Test]
    #[DataProvider('webAuthnServicesRequiringAudit')]
    public function webAuthnServicesResolveOnceAnAuditLoggerIsBound(string $id, string $subsystem): void
    {
        $container = new Container();
        $container->instance(AuditLoggerInterface::class, new NullAuditLogger());
        new AuthServiceProvider()->register($container);

        self::assertIsObject($container->get($id), $subsystem . ' must resolve once audit logging is available');
    }

    #[Test]
    public function theWebAuthnServerItselfFailsClosedWithoutAnAuditLogger(): void
    {
        $container = new Container();
        new AuthServiceProvider()->register($container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(AuditLoggerInterface::class);

        (void) $container->get(WebAuthnServerInterface::class);
    }
}
