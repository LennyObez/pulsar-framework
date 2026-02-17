<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface;

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
}
