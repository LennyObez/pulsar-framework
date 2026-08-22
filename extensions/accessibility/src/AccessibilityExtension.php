<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;
use Pulsar\Extension\Accessibility\Audit\ManualChecklistGenerator;
use Pulsar\Extension\Accessibility\Command\AccessibilityAuditCommand;
use Pulsar\Routing\RouterInterface;

use function is_string;

/**
 * Accessibility extension for WCAG 2.1 AA compliance tooling.
 *
 * Provides composable helpers, static validators, contrast checking,
 * and audit reporting. All components are opt-in building blocks --
 * no middleware blindly injects ARIA into arbitrary HTML.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AccessibilityExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/accessibility';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // a11y:audit is dev/CI only: never registered in production
        if ($this->isDevMode($container)) {
            $container->bind(
                AccessibilityAuditCommand::class,
                static function () use ($container): AccessibilityAuditCommand {
                    /** @var AccessibilityAuditor $auditor */
                    $auditor = $container->get(AccessibilityAuditor::class);

                    /** @var ManualChecklistGenerator $checklist */
                    $checklist = $container->get(ManualChecklistGenerator::class);

                    return new AccessibilityAuditCommand($auditor, $checklist);
                },
            );
        }
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            AccessibilityServiceProvider::class,
        ];
    }

    private function isDevMode(ContainerInterface $container): bool
    {
        if ($container->has('app.debug')) {
            return (bool) $container->get('app.debug');
        }

        if ($container->has('app.environment')) {
            /** @var mixed $env */
            $env = $container->get('app.environment');

            return is_string($env) && $env !== 'production' && $env !== 'prod';
        }

        return false;
    }
}
