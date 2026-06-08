<?php

declare(strict_types=1);

namespace Pulsar\Tooling\PsalmPlugin;

use Override;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Pulsar\Tooling\PsalmPlugin\Hook\ContainerResolutionDetector;
use SimpleXMLElement;

use function class_exists;

/**
 * Psalm plugin for the Pulsar framework.
 *
 * Teaches Psalm about the framework's container-resolution conventions so it
 * can stop reporting UnusedClass / PossiblyUnusedMethod / PossiblyUnusedProperty
 * false positives on classes that are wired through:
 *
 *   - PHP attributes (#[Attribute(...)]), consumed via reflection by the
 *     extension loader, ORM hydrator, route compiler, etc.
 *   - Registry-managed interfaces (MiddlewareInterface, MigrationInterface,
 *     ExtensionInterface, CompilerPassInterface, PaymentProviderInterface,
 *     RealtimeBroadcasterInterface, OAuthProviderInterface, …).
 *   - Framework base classes (Command, Migration, Extension,
 *     AbstractServiceProvider) discovered via composer autoload + manifest.
 *   - Controller / ServiceProvider naming conventions.
 *
 * The hook sets `ClassLikeStorage::$public_api = true` during the
 * `AfterClassLikeVisit` phase, which is exactly what an explicit
 * `@psalm-api` / `@api` docblock tag would do — but without requiring an
 * annotation on every wired class.
 */
final class PulsarPsalmPlugin implements PluginEntryPointInterface
{
    #[Override]
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        // Psalm's PluginRegistrationSocket::registerHooksFromClass() checks
        // `class_exists($handler, false)` — autoload is disabled, so we must
        // force the hook class to load before registering it. Touching the
        // class-string constant alone does not trigger Composer autoload;
        // the `class_exists($class, true)` call below forces it.
        class_exists(ContainerResolutionDetector::class);

        $registration->registerHooksFromClass(ContainerResolutionDetector::class);
    }
}
