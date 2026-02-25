<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

use function array_key_exists;
use function array_values;
use function count;

/**
 * Automated verification of control implementation status.
 *
 * Runs registered verification callbacks against controls to determine
 * whether controls are active and satisfied. Uses "control coverage"
 * language, not "compliance guaranteed."
 */
#[Api(since: '1.0.0')]
final class ControlVerifier
{
    /** @var array<string, callable(): VerificationResult> control ID → verification callback */
    private array $verifiers = [];

    public function __construct(
        private readonly ControlCatalog $catalog,
    ) {}

    /**
     * Register a verification callback for a control.
     *
     * The callback should return a VerificationResult indicating whether
     * the control's implementation is active and functional.
     *
     * @param callable(): VerificationResult $verifier
     */
    public function registerVerifier(string $controlId, callable $verifier): void
    {
        $this->verifiers[$controlId] = $verifier;
    }

    /**
     * Verify a single control.
     */
    public function verify(string $controlId): VerificationResult
    {
        $control = $this->catalog->get($controlId);

        if ($control === null) {
            return new VerificationResult(
                controlId: $controlId,
                passed: false,
                message: 'Control not found in catalog',
            );
        }

        if ($control->status === ControlStatus::NotApplicable) {
            return new VerificationResult(
                controlId: $controlId,
                passed: true,
                message: 'Control marked as not applicable',
            );
        }

        if (!array_key_exists($controlId, $this->verifiers)) {
            return new VerificationResult(
                controlId: $controlId,
                passed: false,
                message: 'No verifier registered for this control',
            );
        }

        return ($this->verifiers[$controlId])();
    }

    /**
     * Verify all controls in the catalog.
     *
     * @return list<VerificationResult>
     */
    public function verifyAll(): array
    {
        $results = [];

        foreach ($this->catalog->all() as $control) {
            $results[] = $this->verify($control->id);
        }

        return $results;
    }

    /**
     * Verify all controls for a specific compliance framework.
     *
     * @return list<VerificationResult>
     */
    public function verifyFramework(string $framework): array
    {
        $results = [];

        foreach ($this->catalog->byFramework($framework) as $control) {
            $results[] = $this->verify($control->id);
        }

        return $results;
    }

    /**
     * Get a summary of verification results.
     *
     * @param list<VerificationResult> $results
     *
     * @return array{total: int, passed: int, failed: int, pass_rate: float}
     */
    public static function summarize(array $results): array
    {
        $total = count($results);
        $passed = count(array_values(array_filter(
            $results,
            static fn(VerificationResult $r): bool => $r->passed,
        )));

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'pass_rate' => $total > 0 ? ((float) $passed / (float) $total) * 100.0 : 0.0,
        ];
    }
}
