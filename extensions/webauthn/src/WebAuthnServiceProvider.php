<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryAuthenticatorRepository;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\WebAuthn\Adapter\WebAuthnServer;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\WebAuthn\Contract\AuthenticatorRepositoryInterface;
use Pulsar\Extension\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\WebAuthn\Contract\WebAuthnServerInterface;

/**
 * Service provider for the WebAuthn/FIDO2 extension.
 *
 * Binds all WebAuthn contracts to adapter implementations and wires
 * ceremony classes with their dependencies.
 */
final class WebAuthnServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(WebAuthnConfig::class, static function () use ($container): WebAuthnConfig {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.webauthn')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.webauthn');
            }

            return WebAuthnConfig::fromArray($configData);
        });

        // Credential repository (in-memory default — apps override with persistent implementation)
        $container->bind(CredentialRepositoryInterface::class, InMemoryCredentialRepository::class);

        // Authenticator repository (in-memory default — apps override with persistent implementation)
        $container->bind(AuthenticatorRepositoryInterface::class, InMemoryAuthenticatorRepository::class);

        // Attestation verifier
        $container->bind(AttestationVerifierInterface::class, static function () use ($container): AttestationVerifierInterface {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);

            return new AttestationVerifier($config->allowedFormats);
        });

        // Registration ceremony
        $container->bind(RegistrationCeremony::class, static function () use ($container): RegistrationCeremony {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);
            /** @var AttestationVerifierInterface $attestationVerifier */
            $attestationVerifier = $container->get(AttestationVerifierInterface::class);
            /** @var CredentialRepositoryInterface $credentialRepository */
            $credentialRepository = $container->get(CredentialRepositoryInterface::class);
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);

            return new RegistrationCeremony($config, $attestationVerifier, $credentialRepository, $auditLogger);
        });

        // Authentication ceremony
        $container->bind(AuthenticationCeremony::class, static function () use ($container): AuthenticationCeremony {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);
            /** @var CredentialRepositoryInterface $credentialRepository */
            $credentialRepository = $container->get(CredentialRepositoryInterface::class);
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);

            return new AuthenticationCeremony($config, $credentialRepository, $auditLogger);
        });

        // WebAuthn server (top-level orchestrator)
        $container->bind(WebAuthnServerInterface::class, static function () use ($container): WebAuthnServerInterface {
            /** @var RegistrationCeremony $registrationCeremony */
            $registrationCeremony = $container->get(RegistrationCeremony::class);
            /** @var AuthenticationCeremony $authenticationCeremony */
            $authenticationCeremony = $container->get(AuthenticationCeremony::class);

            return new WebAuthnServer($registrationCeremony, $authenticationCeremony);
        });

        $container->bind(WebAuthnServer::class, static function () use ($container): WebAuthnServer {
            return $container->get(WebAuthnServerInterface::class);
        });
    }

    public function provides(): array
    {
        return [
            WebAuthnConfig::class,
            CredentialRepositoryInterface::class,
            AuthenticatorRepositoryInterface::class,
            AttestationVerifierInterface::class,
            RegistrationCeremony::class,
            AuthenticationCeremony::class,
            WebAuthnServerInterface::class,
            WebAuthnServer::class,
        ];
    }
}
