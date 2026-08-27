<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa;

use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Dsa\Mapping\DsaMapping;
use Pulsar\Routing\RouterInterface;

/**
 * EU Digital Services Act (Regulation 2022/2065) compliance extension.
 *
 * Provides content moderation logging, transparency reporting,
 * trusted flagger management, notice-and-action mechanisms, and
 * internal complaint handling as required by the DSA for online
 * intermediary services, hosting services, platforms, and VLOPs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DsaExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/dsa';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings handled by DsaServiceProvider
    }

    /**
     * Contribute this extension's controls to the catalog the report reads.
     *
     * {@see DsaMapping} declared ten Digital Services Act controls and nothing
     * anywhere registered them: no provider, no wiring, no boot hook. A search of
     * the tree for its name found the class declaration and nothing else, so every
     * control it declared was unreachable — a claim in a file that no report could
     * print and no assessor could falsify, which is the same defect ADR-0041
     * describes with the failure mode moved from "wrong" to "silent".
     *
     * Boot is the right moment and the only one: {@see \Pulsar\Core\Wiring\ComplianceCatalogWiring}
     * is the last entry in the wiring list and the whole of it has run by the time
     * extensions boot, so the catalog exists and nothing has read it yet — a
     * source contributed after the first read is refused, precisely because the
     * controls it declares would be missing from every report already produced.
     * Contributing CONDITIONALLY on the catalog being bound keeps a MicroKernel
     * deployment — which wires no compliance at all — working rather than fatal.
     *
     * The consequence is deliberate: the DSA controls exist for a deployment that
     * installed this extension, and a deployment that enables `dsa` in
     * config/compliance.php without it gets a report that says so, instead of a
     * framework silently assessed as having no controls.
     */
    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes: applications wire DSA endpoints as needed.
        if ($container->has(ControlCatalog::class)) {
            /** @var ControlCatalog $catalog */
            $catalog = $container->get(ControlCatalog::class);

            // Contributed as a deferred SOURCE, not registered: the catalog builds
            // on first read, and calling DsaMapping::declarations() here would
            // autoload the mapping and construct ten declarations on every boot of
            // every deployment that installs this extension, for a structure only
            // `compliance:report` reads.
            $catalog->contribute(static fn(): array => DsaMapping::declarations());
        }
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            DsaServiceProvider::class,
        ];
    }
}
