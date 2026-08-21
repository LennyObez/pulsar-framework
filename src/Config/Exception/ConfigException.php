<?php

declare(strict_types=1);

namespace Pulsar\Config\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function count;
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
     * `config/extensions.php` names a trust tier the framework does not have.
     *
     * This used to resolve to Community and say nothing. The direction is safe
     * — less privilege, never more — but the file is the ONLY record of what
     * third-party code was granted, and ADR-0023 is cited for SOX, HIPAA and
     * PCI-DSS on the strength of it. A record that can say `core` and mean
     * `community` is not a record. An operator who typed `verifed` gets told,
     * rather than getting a bundled product extension that denies half its own
     * resolutions at 3am for a reason nothing reports.
     *
     * @param list<string> $known Tier values the framework does have.
     */
    #[NoDiscard]
    public static function unknownTrustTier(string $extensionName, string $tier, array $known): self
    {
        return new self(sprintf(
            'Extension "%s" is listed in config/extensions.php with trust tier "%s", which is not '
            . 'a tier this framework has. Use one of: %s. '
            . 'This is refused rather than lowered to "community" because the allow-list is the '
            . 'record of what each extension was granted, and a record that silently means '
            . 'something other than what it says cannot be audited.',
            $extensionName,
            $tier,
            implode(', ', $known),
        ));
    }

    /**
     * `config/extensions.php` grants a capability the framework does not have.
     *
     * Same failure as {@see self::unknownTrustTier()} one level down, and the
     * more misleading of the two: `additional_capabilities` is the mechanism
     * ADR-0023 offers a host that wants to grant one extension one power without
     * promoting its whole tier, so a name that lands nowhere reads in the file
     * as a grant that was made and behaves at runtime as a grant that was not.
     *
     * @param list<string> $known Capability names the framework does have.
     */
    #[NoDiscard]
    public static function unknownExtensionCapability(string $extensionName, string $capability, array $known): self
    {
        return new self(sprintf(
            'Extension "%s" is granted capability "%s" in config/extensions.php, which is not a '
            . 'capability this framework has, so the grant would take effect nowhere. Use one of: %s.',
            $extensionName,
            $capability,
            implode(', ', $known),
        ));
    }

    /**
     * An entry in the `trusted_extensions` allow-list is not shaped like one.
     *
     * A malformed entry used to be skipped, which capped that extension at
     * Community without a word — the same silence as an unknown tier, arrived
     * at by a different typo. `'pulsar/payments' => 'core'` (a string where the
     * shape is an array) is the common one.
     */
    #[NoDiscard]
    public static function malformedTrustedExtension(string $extensionName, string $found): self
    {
        return new self(sprintf(
            'The trusted_extensions entry for "%s" in config/extensions.php is %s, not an array. '
            . "Write it as ['tier' => 'verified'] or "
            . "['tier' => 'community', 'additional_capabilities' => ['DatabaseRaw']].",
            $extensionName,
            $found,
        ));
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
     * Boot-time compliance verification found controls that are not actually
     * satisfied at runtime, while compliance strict mode is on. Unlike
     * {@see self::complianceViolation()} — which reports a config value that could
     * have been tightened — these are controls no configuration change can fix
     * from here (a missing master key, an inactive audit chain), so the boot is
     * refused with the verifier's own findings.
     *
     * @param list<string> $failures One rendered line per failed check.
     */
    #[NoDiscard]
    public static function complianceVerificationFailed(array $failures): self
    {
        return new self(sprintf(
            "Compliance strict mode: boot-time verification failed for %d control(s):\n  - %s\n"
            . 'Resolve the findings above, or set compliance.verification.strict_mode = false '
            . 'to downgrade them to boot warnings.',
            count($failures),
            implode("\n  - ", $failures),
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
