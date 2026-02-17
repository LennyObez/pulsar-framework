<?php

declare(strict_types=1);

namespace Pulsar\ImportExport;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function array_key_exists;
use function array_keys;
use function array_values;
use function sprintf;

/**
 * Central registry for import/export providers.
 *
 * Extensions register their providers during postBoot().
 * The registry is then used by CLI commands and admin UI
 * to discover and dispatch import/export operations.
 */
#[Api(since: '1.0.0')]
final class ImportExportRegistry
{
    /** @var array<string, ImportExportProviderInterface> */
    private array $providers = [];

    /**
     * Register an import/export provider.
     *
     * @throws InvalidArgumentException If a provider with the same name is already registered
     */
    public function register(ImportExportProviderInterface $provider): void
    {
        $name = $provider->name();

        if (array_key_exists($name, $this->providers)) {
            throw new InvalidArgumentException(
                sprintf('Import/export provider "%s" is already registered', $name),
            );
        }

        $this->providers[$name] = $provider;
    }

    /**
     * Get a provider by name, or null if not registered.
     */
    public function getProvider(string $name): ?ImportExportProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Get all registered providers.
     *
     * @return list<ImportExportProviderInterface>
     */
    public function getProviders(): array
    {
        return array_values($this->providers);
    }

    /**
     * Get all registered provider names.
     *
     * @return list<string>
     */
    public function getProviderNames(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Whether a provider with the given name is registered.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->providers);
    }

    /**
     * Export from multiple providers and return combined results.
     *
     * @param list<string> $providerNames Providers to export from (empty = all)
     * @return list<ExportResult>
     */
    public function exportAll(ExportRequest $request, array $providerNames = []): array
    {
        $providers = $providerNames !== []
            ? array_values(array_filter(
                array_map(fn(string $name) => $this->providers[$name] ?? null, $providerNames),
                fn(?ImportExportProviderInterface $p) => $p !== null,
            ))
            : $this->getProviders();

        $results = [];

        foreach ($providers as $provider) {
            $results[] = $provider->export($request);
        }

        return $results;
    }

    /**
     * Route an import to the named provider.
     *
     * @throws InvalidArgumentException If the provider is not registered
     */
    public function importTo(string $providerName, ImportRequest $request): ImportResult
    {
        $provider = $this->getProvider($providerName);

        if ($provider === null) {
            throw new InvalidArgumentException(
                sprintf('Import/export provider "%s" is not registered', $providerName),
            );
        }

        return $provider->import($request);
    }
}
