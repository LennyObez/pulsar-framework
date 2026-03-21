<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_diff;
use function array_keys;
use function implode;
use function in_array;
use function sprintf;

/**
 * Maps notification types to their required legal basis.
 *
 * In regulated mode, every registered notification type must have a legal basis
 * mapping. Call validate() at boot time to enforce this.
 * @api
 */
#[Api(since: '1.0.0')]
final class LegalBasisRegistry
{
    /** @var array<class-string, LegalBasis> */
    private array $mappings = [];

    /** @var list<class-string> */
    private array $registeredTypes = [];

    /**
     * Register a legal basis for a notification type.
     *
     * @param class-string $notificationType
     */
    public function register(string $notificationType, LegalBasis $basis): void
    {
        $this->mappings[$notificationType] = $basis;

        if (!in_array($notificationType, $this->registeredTypes, true)) {
            $this->registeredTypes[] = $notificationType;
        }
    }

    /**
     * Register a notification type that requires a legal basis mapping.
     *
     * Used during boot to declare all known notification types before validation.
     *
     * @param class-string $notificationType
     */
    public function registerType(string $notificationType): void
    {
        if (!in_array($notificationType, $this->registeredTypes, true)) {
            $this->registeredTypes[] = $notificationType;
        }
    }

    /**
     * Get the legal basis for a notification type.
     *
     * @param class-string $notificationType
     */
    public function get(string $notificationType): LegalBasis
    {
        if (!isset($this->mappings[$notificationType])) {
            throw ConfigException::missingRequired(
                $notificationType,
                'legal basis registry',
            );
        }

        return $this->mappings[$notificationType];
    }

    /**
     * Check if a legal basis mapping exists for a notification type.
     *
     * @param class-string $notificationType
     */
    public function has(string $notificationType): bool
    {
        return isset($this->mappings[$notificationType]);
    }

    /**
     * Validate that all registered notification types have a legal basis mapping.
     *
     * Called at boot in regulated mode. Throws ConfigException if any type
     * is missing a mapping.
     *
     * @throws ConfigException If any registered notification type lacks a legal basis
     */
    public function validate(): void
    {
        $mapped = array_keys($this->mappings);
        $missing = array_diff($this->registeredTypes, $mapped);

        if ($missing !== []) {
            throw ConfigException::invalidValue(
                'notification.legal_basis',
                sprintf(
                    'Missing legal basis mapping for notification types: %s',
                    implode(', ', $missing),
                ),
            );
        }
    }
}
