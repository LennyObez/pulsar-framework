<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Configuration for the route model binding subsystem.
 *
 * Supports regulated presets (banking, healthcare, legal) that enforce
 * mandatory authorization on every bound model, and a standard preset
 * that makes authorization opt-in.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class ModelBindingConfig
{
    private const array REGULATED_PRESETS = ['banking', 'healthcare', 'legal'];

    /**
     * @param list<string> $allowedKeyNames
     * @param class-string|null $authorizationHook
     */
    public function __construct(
        public string $preset = 'standard',
        public ?string $authorizationHook = null,
        public array $allowedKeyNames = ['id', 'uuid', 'slug'],
        public bool $compiledMode = false,
    ) {}

    /**
     * Construct from a config array.
     *
     * @param array{
     *     preset?: string,
     *     authorization_hook?: class-string|null,
     *     allowed_key_names?: list<string>,
     *     compiled_mode?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            preset: $data['preset'] ?? 'standard',
            authorizationHook: $data['authorization_hook'] ?? null,
            allowedKeyNames: $data['allowed_key_names'] ?? ['id', 'uuid', 'slug'],
            compiledMode: ($data['compiled_mode'] ?? false) === true,
        );
    }

    /**
     * Whether this configuration uses a regulated preset that mandates
     * authorization on every bound model.
     */
    public function isRegulatedPreset(): bool
    {
        return in_array($this->preset, self::REGULATED_PRESETS, true);
    }
}
