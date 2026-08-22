<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Account;

use Pulsar\Api\Api;

use function usort;

/**
 * Aggregates all AccountSectionProviders and provides a unified view.
 *
 * The CMS account controllers use this registry to collect sections from all
 * active extensions (Forum, Payments, etc.) and render them as tabs.
 *
 * @psalm-api Public registry resolved from the DI container by AccountController
 *            and admin customer-detail templates; not instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final class AccountSectionRegistry
{
    /** @var list<AccountSectionProviderInterface> */
    private array $providers = [];

    /**
     * Register a section provider.
     *
     * Called during service wiring: each extension registers its own provider.
     */
    public function register(AccountSectionProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Get all sections from all registered providers, sorted by priority.
     *
     * @return list<AccountSection>
     */
    public function getSections(string $userId): array
    {
        $sections = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->getSections($userId) as $section) {
                $sections[] = $section;
            }
        }

        usort($sections, static fn(AccountSection $a, AccountSection $b): int => $a->priority <=> $b->priority);

        return $sections;
    }

    /**
     * Render a front-office section by delegating to the owning provider.
     *
     * @param array<string, mixed> $params
     */
    public function renderFrontOffice(string $sectionId, string $userId, array $params = []): string
    {
        foreach ($this->providers as $provider) {
            foreach ($provider->getSections($userId) as $section) {
                if ($section->id === $sectionId) {
                    return $provider->renderFrontOffice($sectionId, $userId, $params);
                }
            }
        }

        return '';
    }

    /**
     * Render a back-office section by delegating to the owning provider.
     *
     * @param array<string, mixed> $params
     */
    public function renderBackOffice(string $sectionId, string $userId, array $params = []): string
    {
        foreach ($this->providers as $provider) {
            foreach ($provider->getSections($userId) as $section) {
                if ($section->id === $sectionId) {
                    return $provider->renderBackOffice($sectionId, $userId, $params);
                }
            }
        }

        return '';
    }

    /**
     * Check if any providers are registered (useful for conditional rendering).
     */
    public function hasProviders(): bool
    {
        return $this->providers !== [];
    }
}
