<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Contracts;

use Pulsar\Api\Api;
use Pulsar\Routing\RouterInterface;

/**
 * Contract for a Studio UI module.
 *
 * Extensions implement this interface to register self-contained panels
 * and pages within the Studio development console. Each module owns its
 * routes under /studio/{moduleId}/ and provides navigation entries
 * for the Studio sidebar.
 * @api
 */
#[Api(since: '1.0.0')]
interface StudioModuleInterface
{
    /**
     * Unique module identifier.
     *
     * Must match the charset [a-z0-9_-]+. Used as the URL segment
     * in /studio/{moduleId}/ routes.
     */
    public function moduleId(): string;

    /**
     * Human-readable module label for the navigation sidebar.
     */
    public function label(): string;

    /**
     * Icon identifier for the navigation sidebar.
     */
    public function icon(): string;

    /**
     * Navigation entries contributed by this module.
     *
     * @return list<StudioNavEntry>
     */
    public function navEntries(): array;

    /**
     * Register module routes on the given router.
     *
     * Routes should be registered under the module's route prefix.
     */
    public function registerRoutes(RouterInterface $router): void;

    /**
     * URL path prefix for this module's routes.
     */
    public function routePrefix(): string;

    /**
     * Sort order for the navigation sidebar.
     *
     * Lower values appear first.
     */
    public function navOrder(): int;
}
