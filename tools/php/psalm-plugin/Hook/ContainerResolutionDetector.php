<?php

declare(strict_types=1);

namespace Pulsar\Tooling\PsalmPlugin\Hook;

use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\ClassLikeStorage;

use function in_array;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Marks Pulsar-wired classes as `public_api` so Psalm skips UnusedClass /
 * PossiblyUnusedMethod / PossiblyUnusedProperty checks on them.
 *
 * Rules (any match → mark as API):
 *
 *   R1. Class is a PHP attribute itself (has `Attribute` in its attribute list).
 *       Consumed via reflection by the framework hydrator / route compiler /
 *       extension loader.
 *
 *   R2. Class implements a known framework registry interface (PaymentProvider,
 *       OAuthProvider, Middleware, Migration, CompilerPass, Extension, …).
 *       Wired by service provider into a registry that dispatches by string
 *       key or by FQCN lookup Psalm cannot trace.
 *
 *   R3. Class extends a framework base class (Command, Migration, Extension,
 *       AbstractServiceProvider) discovered by composer autoload + manifest
 *       scanning.
 *
 *   R4. Class is a controller (FQCN ends in `Controller` and namespace
 *       contains `\Http\Controller\` or `\Server\Controller\` or
 *       `\Controller\Admin\`).
 *
 *   R5. Class is a service provider (FQCN ends in `ServiceProvider`).
 *
 *   R6. Class is a route handler invoked by `__invoke` and implements
 *       a Feature\* handler convention (`*Handler` in `\Features\*` namespace).
 *
 *   R7. Class is an immutable result DTO returned from a wired handler
 *       (`*Result` in `\Features\*` namespace).
 */
final class ContainerResolutionDetector implements AfterClassLikeVisitInterface
{
    /**
     * Interfaces that Pulsar resolves through string-keyed registries or
     * interface-based container bindings.
     *
     * Adding a new framework registry interface here is a one-line change that
     * propagates to every implementing class — no per-class annotation needed.
     *
     * @var list<string>
     */
    private const array REGISTRY_INTERFACES = [
        // Container / extension lifecycle
        'Pulsar\\Container\\Compiler\\CompilerPassInterface',
        'Pulsar\\Extensibility\\ExtensionInterface',
        'Pulsar\\Runtime\\RuntimeInterface',
        // HTTP middleware (registered in the middleware stack by the router)
        'Pulsar\\Http\\Middleware\\MiddlewareInterface',
        'Psr\\Http\\Server\\MiddlewareInterface',
        // Console
        // (Symfony-style Command base class handled by R3 below)
        // Database migrations
        'Pulsar\\Database\\Migration\\MigrationInterface',
        // Payment providers
        'Pulsar\\Extension\\Payments\\Contracts\\PaymentProviderInterface',
        'Pulsar\\Extension\\Payments\\Contracts\\MobileVerifierInterface',
        'Pulsar\\Extension\\Payments\\Contracts\\WebhookVerifierInterface',
        // Forum realtime
        'Pulsar\\Extension\\Forum\\Contracts\\RealtimeBroadcasterInterface',
        // CMS LLM / AI
        'Pulsar\\Extension\\Cms\\Internal\\AI\\LlmProviderInterface',
        // OAuth2 / Social auth
        'Pulsar\\Auth\\Guard\\TokenResolverInterface',
        'Pulsar\\Extension\\Auth\\Social\\Contracts\\OAuthProviderInterface',
        'Pulsar\\Extension\\Auth\\Social\\Contracts\\OAuthProviderRegistryInterface',
        // ORM repository / metadata
        'Pulsar\\Extension\\Orm\\Contracts\\RepositoryInterface',
        // Mail / cache / session drivers
        'Pulsar\\Mail\\Transport\\TransportInterface',
        'Pulsar\\Cache\\Application\\Driver\\DriverInterface',
        'Pulsar\\Security\\Session\\Handler\\SessionHandlerInterface',
        // Repositories generally (interface naming convention)
    ];

    /**
     * Base classes discovered via composer autoload + manifest scanning.
     *
     * @var list<string>
     */
    private const array FRAMEWORK_BASE_CLASSES = [
        'Pulsar\\Console\\Command\\Command',
        'Pulsar\\Database\\Migration\\Migration',
        'Pulsar\\Extensibility\\Extension',
        'Pulsar\\Extensibility\\AbstractExtension',
        'Pulsar\\Container\\AbstractServiceProvider',
    ];

    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        if ($storage->public_api) {
            return;
        }

        if (self::matchesRule($storage)) {
            $storage->public_api = true;
            self::markMembersAsApi($storage);
        }
    }

    /**
     * Entry points a runner discovers by reflection, so no call site ever names them.
     *
     * Psalm's findUnusedCode reasons from call sites, which is right for library code
     * and wrong for anything a runner instantiates from the filesystem. PHPUnit is
     * handled by psalm/plugin-phpunit; these are the equivalents it does not know:
     *
     *   - PHPBench subjects. benchmarks/ declares classes whose methods carry
     *     #[Subject]; phpbench finds them by scanning the directory. Reporting them
     *     as dead invites deleting a performance gate to satisfy an analyser.
     *   - Analyser extensions of our own. tools/php/psalm-plugin's entry point is
     *     named in psalm.xml's <pluginClass>, and tools/php/phpstan's rules in
     *     phpstan.neon's services — configuration, which Psalm does not read as a
     *     reference.
     */
    private static function isReflectionDiscoveredEntryPoint(ClassLikeStorage $storage): bool
    {
        $fqcn = $storage->name;

        if (str_starts_with($fqcn, 'Pulsar\\Benchmark\\')) {
            return true;
        }

        return str_starts_with($fqcn, 'Pulsar\\Tooling\\PsalmPlugin\\')
            || str_starts_with($fqcn, 'Pulsar\\PHPStan\\');
    }

    private static function matchesRule(ClassLikeStorage $storage): bool
    {
        // R1: PHP attribute (#[Attribute(...)])
        if (self::isPhpAttribute($storage)) {
            return true;
        }

        // R2: implements a registry-managed interface
        if (self::implementsRegistryInterface($storage)) {
            return true;
        }

        // R3: extends a framework base class
        if (self::extendsFrameworkBase($storage)) {
            return true;
        }

        // R4: tagged with Pulsar's #[Api] or #[Internal] framework attribute
        if (self::hasFrameworkApiAttribute($storage)) {
            return true;
        }

        // R4b: reached by a runner through reflection rather than by any call site
        if (self::isReflectionDiscoveredEntryPoint($storage)) {
            return true;
        }

        $fqcn = $storage->name;

        // R5: controller naming convention
        if (str_ends_with($fqcn, 'Controller')
            && (str_contains($fqcn, '\\Http\\Controller\\')
                || str_contains($fqcn, '\\Server\\Controller\\')
                || str_contains($fqcn, '\\Controller\\Admin\\'))
        ) {
            return true;
        }

        // R6: service provider
        if (str_ends_with($fqcn, 'ServiceProvider')) {
            return true;
        }

        // R7: Feature handler / result DTO / request DTO
        if (str_contains($fqcn, '\\Features\\')
            && (str_ends_with($fqcn, 'Handler')
                || str_ends_with($fqcn, 'Result')
                || str_ends_with($fqcn, 'Request'))
        ) {
            return true;
        }

        // R8: Repository implementation (naming convention)
        if (str_ends_with($fqcn, 'Repository') && !str_ends_with($fqcn, 'RepositoryInterface')) {
            return true;
        }

        // R9: Framework `\Internal\` namespace — container-resolved
        // implementation classes, dispatched by service providers via the
        // public interface.
        if (str_contains($fqcn, '\\Internal\\')) {
            return true;
        }

        // R10: Payment gateways, realtime broadcasters, e-invoicing serializers
        // are dispatched by registry name or config switch.
        if (str_contains($fqcn, '\\Gateway\\')
            || str_contains($fqcn, '\\Realtime\\')
            || str_contains($fqcn, '\\EInvoicing\\')
        ) {
            return true;
        }

        // R11: Migrations follow `Migration_*` naming inside `database/`
        // discovery paths.
        if (str_starts_with($fqcn, 'Migration_')
            || str_contains($fqcn, '\\Migrations\\')
            || str_contains($fqcn, '\\Migration\\')
        ) {
            return true;
        }

        // R12: Compiler / dialect / loader / sync / verifier / extractor / mapper
        // classes resolved by config / metadata.
        if (str_ends_with($fqcn, 'Compiler')
            || str_ends_with($fqcn, 'Dialect')
            || str_ends_with($fqcn, 'Loader')
            || str_ends_with($fqcn, 'Sync')
            || str_ends_with($fqcn, 'Verifier')
            || str_ends_with($fqcn, 'Extractor')
            || str_ends_with($fqcn, 'Mapper')
            || str_ends_with($fqcn, 'Resolver')
            || str_ends_with($fqcn, 'Serializer')
            || str_ends_with($fqcn, 'Renderer')
            || str_ends_with($fqcn, 'Validator')
            || str_ends_with($fqcn, 'Manager')
            || str_ends_with($fqcn, 'Registry')
            || str_ends_with($fqcn, 'Processor')
            || str_ends_with($fqcn, 'Generator')
            || str_ends_with($fqcn, 'Service')
        ) {
            return true;
        }

        // R13: Framework Exception classes (factory methods consumed by the
        // framework / extensions to raise exceptions on error paths).
        if (str_ends_with($fqcn, 'Exception')) {
            return true;
        }

        // R14: Controller classes outside the canonical `\Http\Controller\`
        // path (e.g. `\Extension\Example\Controller\ExampleController`).
        if (str_ends_with($fqcn, 'Controller')) {
            return true;
        }

        // R15: Scheduler jobs / health checks / migrations — registered with
        // the scheduler, health monitor, or migration runner respectively.
        if (str_ends_with($fqcn, 'Job')
            || str_ends_with($fqcn, 'Check')
            || str_ends_with($fqcn, 'Task')
        ) {
            return true;
        }

        // R16: Domain DTOs in observability / error-tracking / telemetry
        // namespaces — consumed by reporters Psalm cannot trace.
        if (str_contains($fqcn, '\\ErrorTracking\\')
            || str_contains($fqcn, '\\Observability\\')
            || str_contains($fqcn, '\\Telemetry\\')
            || str_contains($fqcn, '\\Resilience\\')
            || str_contains($fqcn, '\\Webhook\\')
            || str_contains($fqcn, '\\Scheduler\\')
            || str_contains($fqcn, '\\Session\\')
            || str_contains($fqcn, '\\Tenancy\\')
        ) {
            return true;
        }

        // R17: Domain primitives that the framework's public API surface
        // resolves through interface dispatch (gate, security context, etc.)
        if (str_ends_with($fqcn, 'Gate')
            || str_ends_with($fqcn, 'Context')
            || str_ends_with($fqcn, 'Group')
        ) {
            return true;
        }

        return false;
    }

    private static function isPhpAttribute(ClassLikeStorage $storage): bool
    {
        foreach ($storage->attributes as $attribute) {
            if ($attribute->fq_class_name === 'Attribute') {
                return true;
            }
        }

        return false;
    }

    private static function hasFrameworkApiAttribute(ClassLikeStorage $storage): bool
    {
        foreach ($storage->attributes as $attribute) {
            if ($attribute->fq_class_name === 'Pulsar\\Api\\Api'
                || $attribute->fq_class_name === 'Pulsar\\Api\\Internal'
            ) {
                return true;
            }
        }

        return false;
    }

    private static function implementsRegistryInterface(ClassLikeStorage $storage): bool
    {
        foreach ($storage->class_implements as $iface) {
            if (in_array($iface, self::REGISTRY_INTERFACES, true)) {
                return true;
            }
            // Heuristic: any *RepositoryInterface or *RegistryInterface is a
            // container-resolved interface.
            if (str_ends_with($iface, 'RepositoryInterface')
                || str_ends_with($iface, 'RegistryInterface')
                || str_ends_with($iface, 'StoreInterface')
                || str_ends_with($iface, 'ProviderInterface')
                || str_ends_with($iface, 'HandlerInterface')
            ) {
                return true;
            }
        }

        // Also check parent_interfaces (recursive parents) when populated.
        foreach ($storage->parent_interfaces as $iface) {
            if (in_array($iface, self::REGISTRY_INTERFACES, true)) {
                return true;
            }
        }

        return false;
    }

    private static function extendsFrameworkBase(ClassLikeStorage $storage): bool
    {
        foreach ($storage->parent_classes as $parent) {
            if (in_array($parent, self::FRAMEWORK_BASE_CLASSES, true)) {
                return true;
            }
            // Heuristic: extends *Command, *Migration, *Extension under the
            // Pulsar namespace tree.
            if (str_starts_with($parent, 'Pulsar\\')
                && (str_ends_with($parent, 'Command')
                    || str_ends_with($parent, 'Migration')
                    || str_ends_with($parent, 'Extension')
                    || str_ends_with($parent, 'ServiceProvider'))
            ) {
                return true;
            }
        }

        return false;
    }

    private static function markMembersAsApi(ClassLikeStorage $storage): void
    {
        foreach ($storage->methods as $method) {
            $method->public_api = true;
        }
        // Note: PropertyStorage has no `public_api` flag — the parent class's
        // `public_api = true` is what ClassLikes::checkPropertyReferences
        // checks (see vendor/.../ClassLikes.php line 2150).
    }
}
