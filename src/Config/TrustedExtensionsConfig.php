<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * Host-side allow-list mapping extension names to allowed trust tiers.
 *
 * Loaded from config/extensions.php. Controls the effective trust tier
 * for each extension — the extension's requested tier is capped by
 * the host's allowed tier.
 */
#[Api(since: '1.0.0')]
readonly class TrustedExtensionsConfig
{
    /**
     * @param array<string, array{tier: TrustTier, additional_capabilities: list<ExtensionCapability>}> $extensions
     */
    public function __construct(
        private array $extensions,
    ) {}

    /**
     * Build from the raw trusted extensions config array.
     *
     * @param array<array-key, mixed> $data Extension name => config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $extensions = [];

        foreach ($data as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                continue;
            }

            /** @var string $tierString */
            $tierString = $config['tier'] ?? 'community';
            $tier = TrustTier::tryFrom($tierString) ?? TrustTier::Community;

            /** @var list<string> $capabilityNames */
            $capabilityNames = $config['additional_capabilities'] ?? [];
            $capabilities = array_values(array_filter(
                array_map(self::resolveCapability(...), $capabilityNames),
                static fn(?ExtensionCapability $c): bool => $c !== null,
            ));

            $extensions[$name] = [
                'tier' => $tier,
                'additional_capabilities' => $capabilities,
            ];
        }

        return new self($extensions);
    }

    /**
     * Resolve the effective tier for an extension.
     *
     * Returns min(requested, allowed), defaulting to Community for unknown extensions.
     */
    public function effectiveTier(string $extensionName, TrustTier $requested): TrustTier
    {
        $allowed = $this->extensions[$extensionName]['tier'] ?? TrustTier::Community;

        return $this->minimumTier($requested, $allowed);
    }

    /**
     * Get additional capabilities granted to a specific extension.
     *
     * @return list<ExtensionCapability>
     */
    #[NoDiscard]
    public function additionalCapabilities(string $extensionName): array
    {
        return $this->extensions[$extensionName]['additional_capabilities'] ?? [];
    }

    private function minimumTier(TrustTier $a, TrustTier $b): TrustTier
    {
        return $a->atLeast($b) ? $b : $a;
    }

    private static function resolveCapability(mixed $name): ?ExtensionCapability
    {
        if (!is_string($name)) {
            return null;
        }

        foreach (ExtensionCapability::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
