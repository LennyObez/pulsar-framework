<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function array_column;
use function array_map;
use function get_debug_type;
use function is_array;
use function is_string;

/**
 * Host-side allow-list mapping extension names to allowed trust tiers.
 *
 * Loaded from config/extensions.php. Controls the effective trust tier
 * for each extension: the extension's requested tier is capped by
 * the host's allowed tier.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TrustedExtensionsConfig
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
     * Every value is either understood or refused. An unrecognised tier used to
     * resolve to Community and an unrecognised capability name used to be
     * filtered out, both without a word; a malformed entry was skipped
     * entirely. All three fail in the safe direction — less privilege, never
     * more — and all three make this file say something other than what the
     * running framework does.
     *
     * That matters more here than the safe direction saves. `config/extensions.php`
     * is the whole of the auditable record ADR-0023 promises: it is the file an
     * auditor reads to learn what third-party code was granted, and the file a
     * reviewer diffs when a grant changes. A record that can read `core` and
     * mean `community` is not a record, and the failure surfaces as denials
     * during a boot nobody connects back to a typo.
     *
     * @param array<array-key, mixed> $data Extension name => config
     * @throws ConfigException If an entry is malformed, or names a tier or
     *                         capability this framework does not have
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $extensions = [];

        /** @var mixed $config */
        foreach ($data as $name => $config) {
            $extensionName = is_string($name) ? $name : (string) $name;

            if (!is_array($config)) {
                throw ConfigException::malformedTrustedExtension($extensionName, get_debug_type($config));
            }

            $extensions[$extensionName] = [
                'tier' => self::resolveTier($extensionName, $config['tier'] ?? 'community'),
                'additional_capabilities' => self::resolveCapabilities(
                    $extensionName,
                    $config['additional_capabilities'] ?? [],
                ),
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

    private static function resolveTier(string $extensionName, mixed $tier): TrustTier
    {
        $resolved = is_string($tier) ? TrustTier::tryFrom($tier) : null;

        if ($resolved === null) {
            throw ConfigException::unknownTrustTier(
                $extensionName,
                is_string($tier) ? $tier : get_debug_type($tier),
                array_column(TrustTier::cases(), 'value'),
            );
        }

        return $resolved;
    }

    /**
     * @return list<ExtensionCapability>
     */
    private static function resolveCapabilities(string $extensionName, mixed $names): array
    {
        if (!is_array($names)) {
            throw ConfigException::malformedTrustedExtension($extensionName, get_debug_type($names));
        }

        $capabilities = [];

        /** @var mixed $name */
        foreach ($names as $name) {
            $capabilities[] = self::resolveCapability($extensionName, $name);
        }

        return $capabilities;
    }

    private static function resolveCapability(string $extensionName, mixed $name): ExtensionCapability
    {
        /** @var ExtensionCapability|null $found */
        $found = is_string($name)
            ? array_find(
                ExtensionCapability::cases(),
                static fn(ExtensionCapability $case): bool => $case->name === $name,
            )
            : null;

        if ($found === null) {
            throw ConfigException::unknownExtensionCapability(
                $extensionName,
                is_string($name) ? $name : get_debug_type($name),
                array_map(
                    static fn(ExtensionCapability $case): string => $case->name,
                    ExtensionCapability::cases(),
                ),
            );
        }

        return $found;
    }
}
