<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;

/**
 * Capabilities that can be granted to extensions based on their trust tier.
 *
 * Each capability controls access to a specific class of framework operations.
 * The CapabilityPolicy maps trust tiers to sets of granted capabilities.
 * @api
 */
#[Api(since: '1.0.0')]
enum ExtensionCapability
{
    /** Resolve services from the container. */
    case ContainerRead;

    /**
     * Override or replace ANY existing binding in the container, including core
     * services (Session, Auth, CsrfGuard, ...). This is the privileged
     * container power and is reserved for Core: it can silently hijack a core
     * security service. Registering a NEW service is the lesser
     * {@see self::ServiceRegister} capability.
     */
    case ContainerWrite;

    /**
     * Register a service the extension provides: a binding for an id that is not
     * already bound — the extension's own interfaces, or one filling an unbound
     * extension point. It cannot rebind an existing service (that is
     * {@see self::ContainerWrite}), so it can never override a core service.
     * The service-layer analogue of {@see self::RouteRegister} (own prefix) vs
     * {@see self::RouteRegisterGlobal} (any path).
     */
    case ServiceRegister;

    /**
     * Decorate an existing service: register a wrapper that RECEIVES the current
     * service and returns a replacement wrapping it. Enhances a service (incl. a
     * core one) without the power to discard it or bind something unrelated —
     * strictly less than {@see self::ContainerWrite} (override/replace). Granted
     * to Verified and above: a decorator can still alter behaviour, so it is not
     * offered at Community.
     */
    case ServiceDecorate;

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

    /**
     * Hold the framework's authentication guard — `GuardInterface` and the
     * `SessionGuard` behind it.
     *
     * Added because nothing in this list named the power, and the alternatives
     * were both wrong. Safe-listing the guard would have handed `login()` to
     * Untrusted, which holds `ContainerRead` and nothing else — the same
     * mistake as the safe-listed audit logger that let it write audit entries
     * without `AuditWrite`. Pricing it at some existing capability would make
     * `config/extensions.php` — the record ADR-0023 asks an auditor to read —
     * say that granting, say, `ServiceDecorate` was the decision, when the
     * decision was to let an extension authenticate a user.
     *
     * The power is real: a holder can call `login()` with an identity it
     * constructed, `logout()` anyone, and `updateIdentity()` on the session in
     * flight. It is also needed by legitimate work — `pulsar/forum` ships its
     * own registration and sign-in pages and their controllers take the guard —
     * which is why this is a capability rather than a refusal.
     *
     * Core and Verified hold it (they hold everything but the three crown
     * jewels); Community and Untrusted are granted from an explicit list that
     * does not name it. That is the line an audited extension shipping a login
     * page sits above and an unaudited one does not.
     */
    case AuthGuardAccess;
}
