<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Pulsar\Extensibility\ExtensionCapability;

use function array_key_exists;
use function in_array;

/**
 * Maps service IDs to required capabilities for deny-by-default enforcement.
 *
 * Services fall into three categories:
 * - Restricted: require a specific capability to resolve
 * - Safe: any tier with ContainerRead can resolve
 * - Unknown: denied for non-Core tiers (deny-by-default)
 *
 * A fourth category exists but is not written here: ids the extension
 * registered ITSELF, tracked by {@see ScopeRegistrations}. Those are consulted
 * after this map, so ownership can rescue an unclassified id and can never buy
 * a restricted one.
 *
 * Every id named below must resolve to a real class or interface.
 * `ServiceRestrictionMapTest` fails otherwise, because a map entry for a type
 * that does not exist gates nothing — the id the framework actually binds falls
 * through to deny-by-default, which reads as "denied" and is therefore invisible
 * for Community while quietly refusing a Verified extension the capability it
 * was granted. Five entries were in exactly that state.
 *
 * @internal Not part of the public API: used by ScopedContainerProxy
 */
final readonly class ServiceRestrictionMap
{
    /**
     * @param array<string, ExtensionCapability> $restrictedServices Service ID => required capability
     * @param list<string> $safeServices Service IDs any tier can read
     */
    public function __construct(
        private array $restrictedServices,
        private array $safeServices,
    ) {}

    /**
     * Create the default restriction map with built-in classifications.
     */
    #[NoDiscard]
    public static function defaults(): self
    {
        return new self(
            restrictedServices: [
                // Crypto: key material access
                'Pulsar\Security\Crypto\MasterKey' => ExtensionCapability::CryptoKeyAccess,
                'Pulsar\Security\Crypto\KeyProviderInterface' => ExtensionCapability::CryptoKeyAccess,

                // Crypto: operations. The password hasher belongs here and not
                // on the safe list: hashing and verifying a password is a
                // cryptographic operation, which is exactly what this
                // capability is for, and it is granted down to Community so an
                // extension shipping its own registration form can hash
                // properly rather than inventing something.
                'Pulsar\Security\Crypto\EncryptorInterface' => ExtensionCapability::CryptoOperations,
                'Pulsar\Security\Crypto\HmacInterface' => ExtensionCapability::CryptoOperations,
                'Pulsar\Auth\Password\PasswordHasherInterface' => ExtensionCapability::CryptoOperations,

                // Authentication. A holder can log an identity in, log anyone
                // out, and rewrite the identity on the session in flight, which
                // no capability in this model named until AuthGuardAccess was
                // added for it — see the enum case for why neither the safe
                // list nor a borrowed capability was an honest answer.
                'Pulsar\Auth\Guard\GuardInterface' => ExtensionCapability::AuthGuardAccess,
                'Pulsar\Auth\Guard\SessionGuard' => ExtensionCapability::AuthGuardAccess,

                // Database. The manager entry named
                // 'Pulsar\Database\DatabaseManagerInterface', which does not
                // exist; the id the composition root binds is
                // ConnectionManagerInterface.
                'Pulsar\Database\ConnectionInterface' => ExtensionCapability::DatabaseRaw,
                'Pulsar\Database\ConnectionManagerInterface' => ExtensionCapability::DatabaseRaw,

                // The tenant context, priced with the database rather than
                // beside it, because `set()` re-points every tenant-scoped
                // query in the request — which is the same power as raw SQL
                // reached by a shorter route. An extension holding DatabaseRaw
                // can already read another tenant's rows by writing the query
                // itself, so this adds no reach to a tier that has it and
                // withholds it from the tiers that do not. Reading the tenant
                // is how `pulsar/payments` scopes a checkout to the right
                // account; refusing it would have the extension write rows
                // belonging to nobody.
                'Pulsar\Tenancy\TenantContext' => ExtensionCapability::DatabaseRaw,

                // Audit. The sink is the pipe; the logger is what writes into
                // it. Both are priced, at different capabilities, because they
                // are different powers: draining or reconfiguring the sink is
                // AuditSinkAccess (Core/Verified), appending an entry is
                // AuditWrite (down to Community, never Untrusted). The sink
                // entry named 'Pulsar\Audit\AuditSinkInterface'; the interface
                // lives under Security\Audit.
                'Pulsar\Security\Audit\AuditSinkInterface' => ExtensionCapability::AuditSinkAccess,
                'Pulsar\Security\Audit\ChainableAuditSinkInterface' => ExtensionCapability::AuditSinkAccess,
                'Pulsar\Audit\AuditLoggerInterface' => ExtensionCapability::AuditWrite,
                'Pulsar\Security\Audit\AuditLogger' => ExtensionCapability::AuditWrite,

                // Filesystem. The entry named
                // 'Pulsar\Storage\FilesystemInterface', which does not exist;
                // storage is reached through the adapter, the manager or the
                // facade, and all three write.
                'Pulsar\Storage\StorageAdapterInterface' => ExtensionCapability::FilesystemWrite,
                'Pulsar\Storage\StorageManager' => ExtensionCapability::FilesystemWrite,
                'Pulsar\Storage\Storage' => ExtensionCapability::FilesystemWrite,

                // Network. Mail and broadcast are outbound traffic an extension
                // originates, which is what NetworkEgress is about — bulk mail
                // from an unaudited extension is the same abuse as an HTTP
                // client pointed anywhere it likes. Both were unclassified, so
                // they were reachable at every tier before this map was
                // consulted for constructor injection at all.
                'Pulsar\Http\Client\HttpClientInterface' => ExtensionCapability::NetworkEgress,
                'Pulsar\Mail\MailManagerInterface' => ExtensionCapability::NetworkEgress,
                'Pulsar\WebSocket\BroadcastManagerInterface' => ExtensionCapability::NetworkEgress,
                'Pulsar\AI\AiClientInterface' => ExtensionCapability::NetworkEgress,

                // Environment
                'Pulsar\Config\Environment' => ExtensionCapability::EnvRead,

                // Config mutation. These are the ids the composition root
                // actually binds (ConfigWiring). The entry that stood here,
                // 'Pulsar\Config\ConfigRepositoryInterface', names a type that
                // does not exist anywhere in the framework, so ConfigWrite
                // gated nothing at all: the repository fell through to
                // deny-by-default, which is the same answer for a Community
                // extension and the WRONG one for a Verified extension that was
                // granted the capability and still could not use it.
                'Pulsar\Config\ConfigRepository' => ExtensionCapability::ConfigWrite,
                'Pulsar\Config\ConfigManager' => ExtensionCapability::ConfigWrite,
                'Pulsar\Config\ConfigManagerInterface' => ExtensionCapability::ConfigWrite,

                // Global middleware. MiddlewareRegister had no enforcement site
                // anywhere in the framework: the policy granted it to Verified
                // and SandboxReach refused these two types to Verified, so the
                // tier table described a control that existed in neither
                // direction. Pricing the two ids the Kernel binds is what makes
                // the grant mean what ADR-0023 says it means; the refusal in
                // SandboxReach still covers Community and Untrusted, which are
                // not granted it.
                'Pulsar\Http\Middleware\MiddlewarePipelineInterface' => ExtensionCapability::MiddlewareRegister,
                'Pulsar\Http\Middleware\MiddlewareRegistry' => ExtensionCapability::MiddlewareRegister,

                // HOST REGISTRIES AN EXTENSION CONTRIBUTES TO.
                //
                // Three tables the framework owns, that exist so extensions can
                // put something of their own into them, and that also hand back
                // what OTHER extensions put there. That second half is why none
                // of them is on the safe list: a safe entry claims resolving the
                // id yields something inert, and a registry holding every
                // extension's contributions is not inert — `getProvider('users')`
                // on the import/export registry returns another extension's
                // exporter, ready to be invoked.
                //
                // They are priced at ServiceRegister for the reason that unifies
                // them: contributing to a host registry IS registering a service,
                // under a key the host owns instead of one in the container. The
                // capability draws the line exactly where it belongs — Untrusted
                // holds ContainerRead alone and may read none of them, Community
                // and above may contribute and therefore may read.
                //
                // All three were unclassified and fell to deny-by-default, which
                // stopped `pulsar/forum` registering its roles in register() and
                // its notification listeners in postBoot(), and stopped
                // `pulsar/analytics`, `pulsar/booking` and `pulsar/payments`
                // registering their import/export providers.
                //
                // What pricing does NOT fix, and what ADR-0023 records under what
                // this does not stop: the role registry is last-write-wins by
                // design (RoleSeeder relies on it to let database roles supersede
                // configured ones), so an extension holding ServiceRegister can
                // redefine the permissions of a role name the host already
                // registered. Refusing the registry does not make that safer — it
                // makes an extension ship its features with no permissions
                // defined at all — so the honest answer is the price plus the
                // sentence in the ADR.
                'Pulsar\Auth\Authorization\RoleRegistryInterface' => ExtensionCapability::ServiceRegister,
                'Pulsar\Event\ListenerProviderInterface' => ExtensionCapability::ServiceRegister,
                'Pulsar\ImportExport\ImportExportRegistry' => ExtensionCapability::ServiceRegister,

                // Security incidents. `report()` is what an extension's own abuse
                // handling calls when it catches something — `pulsar/cms` reports
                // from its comment pipeline — and `recent()`/`find()` read back
                // the record the HOST and every other extension wrote into.
                //
                // Priced at AuditWrite, the capability already carrying "may
                // append to the record the operator reads after an incident".
                // Incidents are not audit entries and the name is a slight
                // stretch, but the tiers are the ones this needs: Core, Verified
                // and Community may report, Untrusted may neither report nor read
                // the incident log. Inventing an IncidentReport capability that
                // is granted to exactly the same three tiers would be a new name
                // for an existing line.
                'Pulsar\Security\Incident\IncidentReporterInterface' => ExtensionCapability::AuditWrite,
            ],
            safeServices: [
                'Psr\Log\LoggerInterface',
                'Psr\EventDispatcher\EventDispatcherInterface',
                'Psr\Clock\ClockInterface',
                'Pulsar\Event\EventDispatcherInterface',
                'Pulsar\Config\AppConfig',
                'Pulsar\Config\SecurityConfig',
                'Pulsar\Config\SecurityHeadersConfig',
                'Pulsar\Config\ObservabilityConfig',
                'Pulsar\Config\CacheConfig',
                'Pulsar\Config\I18nConfig',
                'Pulsar\Observability\Metrics\MetricRegistry',
                // The framework's integration surface for an extension that
                // renders, authorizes, caches or protects a form. Every one of
                // these was already reachable — deny-by-default had never been
                // applied to a constructor parameter, so any of them could be
                // taken by declaring it on a class the extension bound by name.
                // Classifying them says out loud what was true silently, and
                // SandboxReachAnalyzerTest holds each to the inert standard the
                // list claims.
                //
                //  - The template engine renders; it dispenses no services.
                //  - The authorization gate ANSWERS questions about the current
                //    actor. An extension that could not ask would have to invent
                //    its own answer, which is the worse outcome.
                //  - The application cache driver is the app's key/value store.
                //    Deliberately NOT the framework cache, which holds the boot
                //    artifacts and stays denied — see below.
                //  - The CSRF token manager mints and checks tokens for the
                //    extension's own forms. Withholding it makes an extension
                //    ship an unprotected form, not a safer one.
                //  - The consent manager is how an extension asks whether it may
                //    process a visitor's data at all. Denying the question does
                //    not deny the processing.
                //  - The anti-spam pipeline screens submitted content, and its
                //    AI-crawler settings are a readonly value object. This is the
                //    CSRF argument again and it is the whole of it: `pulsar/cms`
                //    screens comments through the pipeline and `pulsar/forum`
                //    screens posts, so an extension denied it does not stop
                //    accepting user content — it accepts it unscreened.
                'Pulsar\View\Engine\TemplateEngineInterface',
                'Pulsar\Auth\Authorization\GateInterface',
                'Pulsar\Cache\Application\Driver\CacheDriverInterface',
                'Pulsar\Security\Csrf\CsrfTokenManagerInterface',
                'Pulsar\DataProtection\ConsentManagerInterface',
                'Pulsar\I18n\Locale\UrlPrefixExtractor',
                'Pulsar\Security\AntiSpam\AntiSpamPipelineInterface',
                'Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig',
                // The business profile — trading name, address, VAT number —
                // read by anything that renders an invoice or a legal notice.
                // A provider of configuration data, like the DTOs above it.
                'Pulsar\Config\BusinessProfileProviderInterface',
                // The configuration an extension ships, and the ONE entry on
                // this list that is safe because of what the proxy does to it
                // rather than because of what the type is. Unclassified, it fell
                // to deny-by-default — and every bundled extension below Core
                // reads its own config through it, so `pulsar/booking` and
                // `pulsar/payments` threw CapabilityDeniedException during
                // boot() and `pulsar/ai-governance` threw on the first
                // resolution of any of its seven contracts. That failure is what
                // the core-tier grant in config/extensions.php was written to
                // route around, and it was recorded there as a defect in
                // deny-by-default's treatment of an extension's OWN service ids
                // — which ScopeRegistrations and isOwnCode() had already
                // answered. The service that was unreachable was the HOST's.
                //
                // Classifying it safe unnarrowed would be worse than the bug:
                // one registry holds EVERY extension's sections, and a real
                // deployment's `payments` section carries a webhook secret and a
                // provider API key, so any tier holding ContainerRead —
                // Untrusted included — could read them. ScopedContainerProxy
                // exchanges the registry for one narrowed to the sections the
                // receiving extension itself ships, the same way it exchanges a
                // container and a router, so what leaves the scope is the
                // extension's own configuration and nothing else.
                'Pulsar\Extensibility\ExtensionConfigRegistry',
                // 'Pulsar\Observability\Metrics\MetricRegistryInterface' stood
                // where MetricRegistry stands now, and names no type in this
                // framework — the fifth of the five phantom entries, and the
                // one that made SandboxReachAnalyzerTest pass VACUOUSLY: its
                // surface walk returns "reaches nothing" for a class it cannot
                // load, so the entry proved itself inert by being absent.
                //
                // 'Pulsar\Container\ContainerInterface' and
                // 'Pulsar\Routing\RouterInterface' were listed here and must
                // never be again: the Kernel binds both to the real container
                // and the real router, so a safe classification handed any
                // proxied extension one call to the objects the proxies exist
                // to mediate. ScopedContainerProxy now refuses them ahead of
                // this map, so re-adding them here would be inert as well as
                // wrong — see its SANDBOX_DEFEATING_SERVICES.
                //
                // 'Pulsar\Extensibility\ExtensionRegistry' was listed here too,
                // and is gone for the general reason rather than a specific
                // one: it is not inert. It hands out every other extension
                // INSTANCE, and its writable half is the boot graph itself —
                // `add()` let a Community extension plant an accomplice under a
                // name the host trusts at Core (which then received the
                // unwrapped container in postBoot), and `setState()` let it
                // mark a security extension Failed so it never booted at all.
                // SandboxReachAnalyzerTest is what keeps that judgement from
                // being a matter of taste: it fails on any entry in this list
                // whose public surface leads to a SandboxReach root.
                //
                // 'Pulsar\Cache\FrameworkCacheInterface' is gone for the same
                // reason with a sharper edge: it is the framework's own boot
                // artifact store — compiled routes, container resolution hints,
                // compiled views. Safe-listing it meant any tier holding
                // ContainerRead, Untrusted included, could rewrite what the
                // next boot executes. Nothing bundled resolves it, so it falls
                // to deny-by-default rather than being priced.
                //
                // 'Pulsar\Audit\AuditLoggerInterface' moved to the restricted
                // map above, under AuditWrite. It was safe-listed while
                // AuditWrite was granted-but-never-checked, so an Untrusted
                // extension — which holds ContainerRead and explicitly not
                // AuditWrite — resolved the host's audit logger and wrote
                // entries with it anyway.
            ],
        );
    }

    /**
     * Get the required capability for a service, or null if safe.
     */
    public function requiredCapability(string $serviceId): ?ExtensionCapability
    {
        return $this->restrictedServices[$serviceId] ?? null;
    }

    /**
     * Check if a service requires a specific capability.
     */
    public function isRestricted(string $serviceId): bool
    {
        return array_key_exists($serviceId, $this->restrictedServices);
    }

    /**
     * Check if a service is on the safe allowlist.
     */
    public function isSafe(string $serviceId): bool
    {
        return in_array($serviceId, $this->safeServices, true);
    }

    /**
     * Check if a service is not classified (not restricted and not safe).
     */
    public function isUnknown(string $serviceId): bool
    {
        return !$this->isRestricted($serviceId) && !$this->isSafe($serviceId);
    }

    /**
     * The safe allowlist, so a test can hold every entry to the standard the
     * list claims for it.
     *
     * A service is on this list because resolving it hands the extension
     * something inert — a value object, a logger, a clock. That is a property
     * of the TYPE, and a checkable one: see
     * {@see SandboxReachAnalyzer::surfaceReach()}. Exposing the list is what
     * lets the check run over it rather than over a copy that drifts.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function safeServices(): array
    {
        return $this->safeServices;
    }

    /**
     * The restricted map, service ID => the capability it costs.
     *
     * @return array<string, ExtensionCapability>
     */
    #[NoDiscard]
    public function restrictedServices(): array
    {
        return $this->restrictedServices;
    }
}
