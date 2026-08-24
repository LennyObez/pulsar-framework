<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_key_exists;

/**
 * Configuration for the route model binding subsystem.
 *
 * Supports regulated presets (banking, healthcare, legal) that enforce
 * mandatory authorization on every bound model, and a standard preset
 * that makes authorization opt-in.
 *
 * ## The preset is a type, not a string
 *
 * {@see $preset} was a free-form string matched with a strict, case-sensitive
 * `in_array` against the regulated names, so `Banking` and `bankng` both missed
 * the regulated branch and selected the permissive one without a word. The
 * answer is split across two places on purpose, because either half alone leaves
 * the hole open:
 *
 *  - {@see BindingPreset} makes an invalid preset unexpressible in PHP. Nothing
 *    downstream — this DTO, the middleware, an extension — can hold a preset
 *    that is not one of the four, so no consumer needs to re-validate one.
 *  - {@see BindingPreset::fromConfig()} refuses an unrecognized string at the
 *    config-file boundary, which is where the string actually comes from. This
 *    happens during config load, at boot, so a typo is a failed boot rather than
 *    a permissive posture discovered in an incident review.
 *
 * An enum with a defaulting conversion would have been the same bug wearing a
 * type, so {@see fromArray()} does not default: an absent key means the operator
 * said nothing and gets {@see DEFAULT_PRESET}, while a PRESENT key that is not
 * a preset means they tried to say something and got it wrong, and that is not
 * a state this setting has a safe reading for.
 *
 * ## Saying nothing is not saying "standard"
 *
 * {@see DEFAULT_PRESET} is regulated, and its docblock says why. Every path
 * that reaches this class without a preset written down — an absent key, an
 * absent config/model_binding.php, a bare `new ModelBindingConfig()` at a
 * composition seam — lands on mandatory authorization rather than on the
 * enforcement level where an unidentified caller is handed the model.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class ModelBindingConfig
{
    /**
     * The posture an application gets when it says nothing.
     *
     * ## Why this is not {@see BindingPreset::Standard}
     *
     * It was, and config/model_binding.php shipped `'banking'` — so the file a
     * reviewer reads and the code an application actually runs disagreed, and
     * the permissive one was reached by deleting a file rather than by editing
     * one. Two paths arrive here with no preset written down anywhere:
     * {@see fromArray()} with no `preset` key, and
     * {@see \Pulsar\Core\Wiring\ModelBindingWiring}'s `new ModelBindingConfig()`
     * when the config repository holds no entry at all. Both used to select the
     * preset under which a caller the framework cannot identify is handed the
     * model, which is exactly the state the typed preset was introduced to make
     * unreachable by accident.
     *
     * Silence is not a decision, so it may not select the weaker of two
     * enforcement levels. It selects the stronger one, and the way to get the
     * weaker one is to write `'preset' => 'standard'` where a reviewer can grep
     * for it.
     *
     * ## Why a regulated NAME rather than a fourth "regulated" case
     *
     * The three regulated names differ in no behaviour — see
     * {@see BindingPreset::isRegulated()} — so what defaults here is the
     * enforcement LEVEL, and the name is documentary. Banking is the name
     * config/model_binding.php ships, and the two are pinned to each other by a
     * test that reads the shipped file. Rename it to the regime you answer to;
     * that never changes enforcement. Only `standard` does.
     */
    public const BindingPreset DEFAULT_PRESET = BindingPreset::Banking;

    /**
     * @param list<string> $allowedKeyNames
     * @param class-string|null $authorizationHook
     */
    public function __construct(
        public BindingPreset $preset = self::DEFAULT_PRESET,
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
     *
     * @throws ConfigException When `preset` is present but is not one of the
     *                         names {@see BindingPreset} defines.
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            // array_key_exists, not `?? default`: `'preset' => null` is a value
            // somebody wrote, not a key they omitted, and it is not one of the
            // four names. Coalescing would read it as the default preset, which
            // is the class of silence this setting exists to end.
            preset: array_key_exists('preset', $data)
                ? BindingPreset::fromConfig($data['preset'])
                : self::DEFAULT_PRESET,
            authorizationHook: $data['authorization_hook'] ?? null,
            allowedKeyNames: $data['allowed_key_names'] ?? ['id', 'uuid', 'slug'],
            compiledMode: ($data['compiled_mode'] ?? false) === true,
        );
    }

    /**
     * Whether this configuration uses a regulated preset that mandates
     * authorization on every bound model.
     *
     * Delegates rather than deciding: the enforcement level is a property of the
     * preset, and a second opinion held here could disagree with the one the
     * enum gives every other caller.
     */
    public function isRegulatedPreset(): bool
    {
        return $this->preset->isRegulated();
    }
}
