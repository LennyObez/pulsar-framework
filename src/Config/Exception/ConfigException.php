<?php

declare(strict_types=1);

namespace Pulsar\Config\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function implode;
use function sprintf;

/**
 * Exception thrown for configuration errors.
 * @api
 */
#[Api(since: '1.0.0')]
final class ConfigException extends RuntimeException
{
    /**
     * Config file not found at path.
     */
    #[NoDiscard]
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Configuration file not found: "%s"', $path));
    }

    /**
     * Config file did not return an array.
     */
    #[NoDiscard]
    public static function invalidValue(string $path, string $reason): self
    {
        return new self(sprintf('Invalid configuration value in "%s": %s', $path, $reason));
    }

    /**
     * Required config key is missing.
     */
    #[NoDiscard]
    public static function missingRequired(string $key, string $context): self
    {
        return new self(sprintf('Missing required configuration key "%s" in %s', $key, $context));
    }

    /**
     * One or more config files carry keys the framework does not recognize,
     * and strict key checking is enabled. Aggregated so the operator sees every
     * typo at once rather than one boot failure at a time.
     *
     * @param list<string> $descriptions Per-section lines already rendered with
     *                                   any "did you mean" suggestions.
     */
    #[NoDiscard]
    public static function unknownKeys(array $descriptions): self
    {
        return new self(sprintf(
            "Unknown configuration key(s) detected with strict key checking enabled:\n  - %s\n"
            . 'Fix the key names, or set config.strict_keys = false (or PULSAR_CONFIG_STRICT=false) '
            . 'to downgrade this to a warning.',
            implode("\n  - ", $descriptions),
        ));
    }

    /**
     * A security-relevant setting is looser than an enabled compliance framework
     * requires while compliance strict mode is on, so the boot is refused rather
     * than silently tightened. Names the control, the offending and required
     * values, and the framework(s) that mandate the stricter setting.
     *
     * @param non-empty-string $control    Human-readable control, e.g. 'session idle timeout'.
     * @param string           $actual     The operator's current value, rendered for display.
     * @param string           $required   The value the strictest framework demands, rendered.
     * @param list<string>     $frameworks Framework labels that drove the requirement.
     */
    #[NoDiscard]
    public static function complianceViolation(string $control, string $actual, string $required, array $frameworks): self
    {
        return new self(sprintf(
            'Compliance strict mode: %s is %s but the enabled framework(s) [%s] require %s. '
            . 'Bring the configuration into compliance, or set compliance.verification.strict_mode = false '
            . 'to have the framework tighten it automatically with a boot warning instead.',
            $control,
            $actual,
            implode(', ', $frameworks),
            $required,
        ));
    }
}
