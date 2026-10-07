<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function array_map;
use function implode;
use function in_array;
use function sprintf;
use function strtolower;

/**
 * Validates that enum-like environment values are recognized.
 *
 * A typo'd APP_ENV or APP_DEBUG resolves via a fail-secure default (unrecognized
 * APP_ENV -> production, unrecognized APP_DEBUG -> false), which is safe but
 * silent — the operator's real intent is lost. This check reads the RAW values
 * from the environment (before that mapping) and reports any unrecognized value
 * loudly, with the accepted set, so the mistake is caught at deploy time rather
 * than shipped.
 */
#[Internal]
final readonly class EnvironmentValidationCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'environment-values';

    /** Boolean literals accepted by AppConfig::parseBool. */
    private const array VALID_BOOLEANS = ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'];

    public function __construct(
        private Environment $environment,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates that enum-like environment values (APP_ENV, APP_DEBUG) are recognized';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        $issues = [];
        $recommendations = [];

        $rawEnv = $this->environment->get('APP_ENV');

        if ($rawEnv !== null && $rawEnv !== '' && EnvironmentMode::tryFrom($rawEnv) === null) {
            $issues[] = sprintf("APP_ENV='%s' is not a recognized environment", $rawEnv);
            $recommendations[] = sprintf(
                'Set APP_ENV to one of: %s. An unrecognized value is treated as production (fail-secure).',
                implode(', ', array_map(static fn(EnvironmentMode $m): string => $m->value, EnvironmentMode::cases())),
            );
        }

        $rawDebug = $this->environment->get('APP_DEBUG');

        if ($rawDebug !== null && $rawDebug !== '' && !in_array(strtolower($rawDebug), self::VALID_BOOLEANS, true)) {
            $issues[] = sprintf("APP_DEBUG='%s' is not a recognized boolean", $rawDebug);
            $recommendations[] = 'Set APP_DEBUG to true or false (also accepted: 1/0, yes/no, on/off). '
                . 'An unrecognized value is treated as false.';
        }

        if ($issues === []) {
            return CheckResult::pass(self::CHECK_NAME, 'Environment enum values are recognized');
        }

        return CheckResult::error(self::CHECK_NAME, implode('; ', $issues), $recommendations);
    }
}
