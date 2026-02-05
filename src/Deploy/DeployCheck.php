<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use Pulsar\Api\Internal;
use Pulsar\Deploy\Exception\DeployException;
use Throwable;

use function in_array;
use function sprintf;

/**
 * Orchestrates deploy readiness checks.
 *
 * Accepts a list of check implementations, runs them against a target environment,
 * and aggregates results into a DeployReport.
 */
#[Internal]
final class DeployCheck implements DeployCheckRunnerInterface
{
    /** @var list<DeployCheckInterface> */
    private array $checks;

    /**
     * @param list<DeployCheckInterface> $checks Initial set of deploy checks
     */
    public function __construct(array $checks = [])
    {
        $this->checks = $checks;
    }

    /**
     * Register an additional deploy check.
     */
    public function register(DeployCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Run all registered checks against the given environment.
     *
     * @param string $environment The target environment (local, staging, production)
     * @throws DeployException If report generation fails unexpectedly
     */
    public function run(string $environment): DeployReport
    {
        $validEnvironments = ['local', 'staging', 'production'];

        if (!in_array($environment, $validEnvironments, true)) {
            throw DeployException::invalidEnvironment($environment);
        }

        $results = [];
        $passed = 0;
        $warnings = 0;
        $errors = 0;

        foreach ($this->checks as $check) {
            try {
                $result = $check->check($environment);
            } catch (Throwable $e) {
                $result = CheckResult::error(
                    $check->getName(),
                    sprintf('Check failed with exception: %s', $e->getMessage()),
                    ['Review the check implementation and its dependencies.'],
                );
            }

            $results[] = $result;

            match ($result->severity) {
                CheckSeverity::Pass => $passed++,
                CheckSeverity::Warning => $warnings++,
                CheckSeverity::Error => $errors++,
            };
        }

        return new DeployReport(
            results: $results,
            passed: $passed,
            warnings: $warnings,
            errors: $errors,
            environment: $environment,
        );
    }

    /**
     * Get all registered checks.
     *
     * @return list<DeployCheckInterface>
     */
    public function checks(): array
    {
        return $this->checks;
    }
}
