<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * WebAuthn/FIDO2 authentication extension.
 *
 * Provides WebAuthn registration and authentication ceremonies,
 * passkey (resident credential) support, authenticator management,
 * and integration with the framework's 2FA system.
 */
final class WebAuthnExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/webauthn';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // WebAuthn ceremony endpoints
        $router->group('/webauthn', function (RouterInterface $router): void {
            // Registration (attestation) ceremony
            $router->post('/register/options', 'webauthn.register.options');
            $router->post('/register/verify', 'webauthn.register.verify');

            // Authentication (assertion) ceremony
            $router->post('/authenticate/options', 'webauthn.authenticate.options');
            $router->post('/authenticate/verify', 'webauthn.authenticate.verify');

            // Authenticator management
            $router->get('/authenticators', 'webauthn.authenticators.list');
            $router->put('/authenticators/{id}/rename', 'webauthn.authenticators.rename');
            $router->delete('/authenticators/{id}', 'webauthn.authenticators.revoke');
        });
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            WebAuthnServiceProvider::class,
        ];
    }
}
