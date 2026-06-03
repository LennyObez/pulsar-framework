<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceProfile;
use Throwable;

use function array_merge;

/**
 * Orchestrates all compliance verification checks.
 *
 * Combines runtime verification, data-path verification, regression detection,
 * conflict detection, custom controls, and evidence chain recording into a
 * single coherent verification pipeline.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ComplianceVerificationEngine
{
    /**
     * @param list<ComplianceCheckInterface> $checks Additional pluggable checks
     */
    public function __construct(
        private ComplianceProfile $profile,
        private RuntimeVerifier $runtimeVerifier,
        private ConflictDetector $conflictDetector,
        private CustomControlRegistry $customControlRegistry,
        private VerificationConfig $config,
        private ?EvidenceChain $evidenceChain = null,
        private array $checks = [],
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Run the full verification pipeline.
     *
     * 1. Runs all registered ComplianceCheckInterface implementations
     * 2. Runs RuntimeVerifier checks
     * 3. Runs custom control checks
     * 4. Detects cross-framework conflicts
     * 5. Optionally records to evidence chain
     */
    #[NoDiscard]
    public function verify(): VerificationReport
    {
        $results = [];

        // 1. Pluggable compliance checks
        foreach ($this->checks as $check) {
            $results[] = $check->execute();
        }

        // 2. Runtime verification
        $results = array_merge($results, $this->runtimeVerifier->verify());

        // 3. Custom controls
        $results = array_merge($results, $this->customControlRegistry->verifyAll());

        // 4. Cross-framework conflicts
        $conflicts = $this->conflictDetector->detect($this->profile->enabledFrameworks);

        $report = new VerificationReport(
            frameworks: $this->profile->enabledFrameworks,
            results: $results,
            conflicts: $conflicts,
            generatedAt: time(),
        );

        // 5. Record to evidence chain
        $this->recordEvidence($report);

        return $report;
    }

    /**
     * Run verification including data-path checks for classified routes.
     *
     * @param list<array{path: string, classification: string, middleware: list<string>}> $routes
     */
    #[NoDiscard]
    public function verifyWithRoutes(array $routes, DataPathVerifier $dataPathVerifier): VerificationReport
    {
        $results = [];

        // Pluggable compliance checks
        foreach ($this->checks as $check) {
            $results[] = $check->execute();
        }

        // Runtime verification
        $results = array_merge($results, $this->runtimeVerifier->verify());

        // Data path verification
        $results = array_merge($results, $dataPathVerifier->verify($routes));

        // Custom controls
        $results = array_merge($results, $this->customControlRegistry->verifyAll());

        // Cross-framework conflicts
        $conflicts = $this->conflictDetector->detect($this->profile->enabledFrameworks);

        $report = new VerificationReport(
            frameworks: $this->profile->enabledFrameworks,
            results: $results,
            conflicts: $conflicts,
            generatedAt: time(),
        );

        $this->recordEvidence($report);

        return $report;
    }

    /**
     * Run verification with regression detection.
     *
     * @throws ComplianceRegressionException in strict mode when regressions are found
     */
    #[NoDiscard]
    public function verifyWithRegressions(RegressionDetector $regressionDetector, RegressionInput $input): VerificationReport
    {
        $regressions = $regressionDetector->detect(
            sessionIdleTimeout: $input->sessionIdleTimeout,
            passwordMinLength: $input->passwordMinLength,
            hstsMaxAge: $input->hstsMaxAge,
            encryptionAtRest: $input->encryptionAtRest,
            mfaScope: $input->mfaScope,
            tamperEvidentAudit: $input->tamperEvidentAudit,
        );

        if ($this->config->strictMode && $regressions !== []) {
            throw ComplianceRegressionException::fromViolations($regressions);
        }

        $results = [];

        foreach ($this->checks as $check) {
            $results[] = $check->execute();
        }

        $results = array_merge($results, $this->runtimeVerifier->verify());
        $results = array_merge($results, $this->customControlRegistry->verifyAll());

        $conflicts = $this->conflictDetector->detect($this->profile->enabledFrameworks);

        $report = new VerificationReport(
            frameworks: $this->profile->enabledFrameworks,
            results: $results,
            conflicts: $conflicts,
            regressions: $regressions,
            generatedAt: time(),
        );

        $this->recordEvidence($report);

        return $report;
    }

    /**
     * Record the completed report to the evidence chain, if one is configured.
     *
     * Evidence recording is a side-effect that must never discard an
     * already-built report: a sodium/json failure inside the chain is logged
     * (when a logger is available) and swallowed so the caller still receives
     * the verification result.
     */
    private function recordEvidence(VerificationReport $report): void
    {
        if ($this->evidenceChain === null) {
            return;
        }

        try {
            $this->evidenceChain->record($report);
        } catch (Throwable $e) {
            $this->logger?->error(
                'Compliance evidence chain recording failed; verification report preserved.',
                ['exception' => $e],
            );
        }
    }

    /**
     * Whether verification is enabled.
     */
    #[NoDiscard]
    public function isEnabled(): bool
    {
        return $this->config->enabled;
    }

    /**
     * Whether boot-time checking is enabled.
     */
    #[NoDiscard]
    public function shouldCheckAtBoot(): bool
    {
        return $this->config->enabled && $this->config->bootCheck;
    }
}
