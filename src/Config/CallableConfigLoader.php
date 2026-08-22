<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Closure;
use Pulsar\Api\Internal;

/**
 * A {@see ConfigLoaderInterface} backed by a closure.
 *
 * Lets a wiring register its config DTO with {@see ConfigManager} without
 * ConfigManager ever importing that DTO: the wiring — which already owns and
 * imports its DTO — supplies the class-string and a factory closure, and the
 * loader-walk in {@see ConfigManager::load()} builds the DTO into the
 * repository. This inverts the dependency (module → Config, never Config →
 * module) so a single config source of truth stays boundary-clean.
 */
#[Internal]
final readonly class CallableConfigLoader implements ConfigLoaderInterface
{
    /**
     * @param class-string                                                       $configClass
     * @param Closure(array<string, mixed>, Environment, ConfigRepository): object $factory
     *        A factory may declare fewer parameters (e.g. just the data array)
     *        when it does not need the environment or repository.
     */
    public function __construct(
        private string $configClass,
        private Closure $factory,
    ) {}

    public function configClass(): string
    {
        return $this->configClass;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function load(array $data, Environment $environment, ConfigRepository $repository): object
    {
        return ($this->factory)($data, $environment, $repository);
    }
}
