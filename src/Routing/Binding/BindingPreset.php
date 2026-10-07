<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_map;
use function get_debug_type;
use function implode;
use function is_string;
use function sprintf;

/**
 * How hard route model binding authorizes the models it resolves.
 *
 * Two enforcement levels under four names. Under a regulated preset,
 * authorization is MANDATORY on every bound model: a request with no
 * authenticated caller is a 401 before the hook is even consulted, a failed
 * policy is a 403, and the `_without_authorization` route attribute is refused
 * on any route that is not explicitly `#[PublicRoute]`. (That opt-out is a route
 * attribute and only a route attribute: no `#[WithoutAuthorization]` class and
 * no router method for it exist.) Under {@see self::Standard} the policy check
 * is opt-in, and a caller the framework cannot identify is handed the model.
 *
 * ## Why this is a type and not a string
 *
 * It used to be a string on {@see ModelBindingConfig}, tested with a strict,
 * case-sensitive `in_array` against the three regulated names. `Banking` and
 * `bankng` therefore missed the regulated branch and landed on the permissive
 * one, silently: a security posture that turned on a spelling, and failed OPEN
 * when the spelling was wrong. Nothing reported it, because from the code's
 * point of view an unknown preset and a deliberately permissive one were the
 * same value.
 *
 * A closed type fixes the half of that which lives inside PHP — an invalid
 * preset can no longer be written down at all. `BindingPreset::Bankng` is a
 * static-analysis failure and a fatal at the call site, not a posture nobody
 * notices until an incident review. It is the same answer {@see BindingScope}
 * gives for the same reason: a scope cannot be misspelled into the permissive
 * branch either.
 *
 * ## The type alone would not have been enough
 *
 * Configuration arrives as a string from a PHP file, so somewhere a string has
 * to become one of these. That conversion is the whole vulnerability, and
 * `tryFrom($value) ?? self::Standard` would reproduce the original defect
 * exactly, one layer down and harder to see. {@see fromConfig()} is therefore
 * the only supported way in, and it REFUSES what it does not recognize —
 * loudly, during config load, before a single request is served. Do not
 * reintroduce a defaulting conversion beside it.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
enum BindingPreset: string
{
    /** Regulated: banking. */
    case Banking = 'banking';

    /** Regulated: healthcare. */
    case Healthcare = 'healthcare';

    /** Regulated: legal. */
    case Legal = 'legal';

    /**
     * Authorization is opt-in: a request with no authenticated identity binds
     * the model and reaches the controller with no policy check at all.
     */
    case Standard = 'standard';

    /**
     * The setting this enum types, in the dotted form {@see ConfigException}
     * uses everywhere else.
     *
     * The key rather than a file path, because the same section is readable from
     * two places — config/model_binding.php in the host, and a `model_binding`
     * extension section — and a message naming one file would be wrong for a
     * reader who configured the other. The file the operator should be editing
     * is named in the reason instead, where it is advice rather than a claim
     * about where the value came from.
     */
    private const string CONFIG_KEY = 'model_binding.preset';

    /**
     * Whether this preset makes authorization mandatory on every bound model.
     *
     * Stated as "anything that is not {@see self::Standard}", never as a list of
     * the regulated names. The two formulations agree today and diverge the
     * moment a case is added: a list leaves the new preset permissive until
     * someone remembers to extend it, while this leaves it enforcing until
     * someone deliberately exempts it. Only one of those directions is safe to
     * get wrong.
     *
     * The three regulated names differ in NO behaviour. `preset` reaches code
     * only through this method — never an audit record, never a response — so
     * the name documents which regime you answer to and the value picks one of
     * the two enforcement levels.
     */
    public function isRegulated(): bool
    {
        return $this !== self::Standard;
    }

    /**
     * Read a preset out of configuration, refusing anything unrecognized.
     *
     * Accepts `mixed` rather than `string` on purpose: a config file is PHP, so
     * `'preset' => true` is exactly as writable as `'preset' => 'bankng'`, and a
     * TypeError would name the type instead of the setting. Both arrive here and
     * leave as the same clear refusal.
     *
     * Throws rather than defaulting. There is no value of this setting that
     * means "I do not know", because the two things it could fall back to differ
     * in whether an unauthenticated caller is handed the model.
     *
     * @throws ConfigException When the value is not one of the four preset names.
     */
    #[NoDiscard]
    public static function fromConfig(mixed $value): self
    {
        if (is_string($value)) {
            $preset = self::tryFrom($value);

            if ($preset !== null) {
                return $preset;
            }
        }

        throw ConfigException::invalidValue(self::CONFIG_KEY, sprintf(
            '%s is not a route model binding preset. Valid values: %s. Set one of them as the '
            . "'preset' key of config/model_binding.php. An unrecognized preset is refused rather "
            . "than read as '%s', because the difference between the two is whether a caller the "
            . 'framework cannot identify is handed the model.',
            self::render($value),
            implode(', ', array_map(static fn(self $case): string => $case->value, self::cases())),
            self::Standard->value,
        ));
    }

    /**
     * Render the offending value for the refusal message.
     *
     * A string is quoted so a stray space or capital is visible; anything else
     * is named by type, which is the actual mistake in that case.
     */
    private static function render(mixed $value): string
    {
        return is_string($value)
            ? sprintf('"%s"', $value)
            : get_debug_type($value);
    }
}
