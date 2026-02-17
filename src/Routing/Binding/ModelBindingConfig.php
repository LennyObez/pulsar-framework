<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;
use function is_array;
use function is_bool;
use function is_string;

/**
 * Configuration for the route model binding subsystem.
 *
 * Supports regulated presets (banking, healthcare, legal) that enforce
 * mandatory authorization on every bound model, and a standard preset
 * that makes authorization opt-in.
 */
#[Api(since: '1.0.0-rc.11')]
readonly class ModelBindingConfig
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string $preset */
        $preset = isset($data['preset']) && is_string($data['preset']) ? $data['preset'] : 'standard';

        /** @var class-string|null $authorizationHook */
        $authorizationHook = isset($data['authorization_hook']) && is_string($data['authorization_hook'])
            ? $data['authorization_hook']
            : null;

        /** @var list<string> $allowedKeyNames */
        $allowedKeyNames = isset($data['allowed_key_names']) && is_array($data['allowed_key_names'])
            ? $data['allowed_key_names']
            : ['id', 'uuid', 'slug'];

        $compiledMode = isset($data['compiled_mode']) && is_bool($data['compiled_mode'])
            ? $data['compiled_mode']
            : false;

        return new self(
            preset: $preset,
            authorizationHook: $authorizationHook,
            allowedKeyNames: $allowedKeyNames,
            compiledMode: $compiledMode,
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
