<?php

declare(strict_types=1);

namespace Pulsar\Tooling\PsalmPlugin;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

use function dirname;

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
 *   - Controller / ServiceProvider naming conventions (classes in
 *     `*\Http\Controller\*`, `*\Server\Controller\*`, `*ServiceProvider`).
 *
 * The hook sets `ClassLikeStorage::$public_api = true` during the
 * `AfterClassLikeVisit` phase, which is exactly what an explicit
 * `@psalm-api` / `@api` docblock tag would do — but without requiring an
 * annotation on every wired class.
 *
 * This replaces the prior session's compromise of bulk-annotating ~1500
 * classes/members and adding 77 file-narrow XML suppressions. Truly-dead code
 * remains visible because the plugin only marks classes that match an
 * actual framework wiring convention.
 */
final class PulsarPsalmPlugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        $hookDir = __DIR__ . DIRECTORY_SEPARATOR . 'Hook';

        require_once $hookDir . DIRECTORY_SEPARATOR . 'ContainerResolutionDetector.php';

        $registration->registerHooksFromClass(Hook\ContainerResolutionDetector::class);
    }
}
