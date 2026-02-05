<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;

/**
 * Capabilities that can be granted to extensions based on their trust tier.
 *
 * Each capability controls access to a specific class of framework operations.
 * The CapabilityPolicy maps trust tiers to sets of granted capabilities.
 */
#[Api(since: '1.0.0')]
enum ExtensionCapability
{
    /** Resolve services from the container. */
    case ContainerRead;

    /** Bind or register new services in the container. */
    case ContainerWrite;

    /** Register routes under the extension's namespace prefix. */
    case RouteRegister;

    /** Register routes at any path (Core/Verified only). */
    case RouteRegisterGlobal;

    /** Register global middleware. */
    case MiddlewareRegister;

    /** Access MasterKey and KeyProviderInterface. */
    case CryptoKeyAccess;

    /** Use EncryptorInterface, HmacInterface for crypto operations. */
    case CryptoOperations;

    /** Write entries to the audit log. */
    case AuditWrite;

    /** Direct access to AuditSinkInterface. */
    case AuditSinkAccess;

    /** Raw SQL and database connection access. */
    case DatabaseRaw;

    /** Write to the filesystem. */
    case FilesystemWrite;

    /** Register CLI commands. */
    case CommandRegister;

    /** HTTP client and outbound network access. */
    case NetworkEgress;

    /** Read environment variables. */
    case EnvRead;

    /** Mutate the config repository. */
    case ConfigWrite;

    /** Execute external processes. */
    case ProcessExec;
}
