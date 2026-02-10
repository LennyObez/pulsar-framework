<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use RuntimeException;

use function array_diff;
use function array_key_exists;
use function implode;
use function sprintf;

/**
 * Validates the bench-pipeline manifest against semantic contracts.
 *
 * Ensures each request class has its required middleware, auth type,
 * outputs, and side-effects as defined by the plan's semantic contracts.
 */
final class ManifestValidator
{
    /**
     * Semantic contracts: what each request class MUST exercise.
     *
     * @var array<string, array{middleware: list<string>, auth: string|null, outputs: list<string>, side_effects: list<string>}>
     */
    private const array CONTRACTS = [
        'request.anonymous_json_api' => [
            'middleware' => ['routing', 'error-handler', 'content-negotiation'],
            'auth' => null,
            'outputs' => ['json_serialization'],
            'side_effects' => [],
        ],
        'request.authenticated_session' => [
            'middleware' => ['routing', 'error-handler', 'session-start', 'auth-guard', 'authorization'],
            'auth' => 'session',
            'outputs' => ['json_serialization'],
            'side_effects' => ['session_read_write'],
        ],
        'request.authenticated_token' => [
            'middleware' => ['routing', 'error-handler', 'token-resolver', 'auth-guard', 'authorization'],
            'auth' => 'bearer_token',
            'outputs' => ['json_serialization'],
            'side_effects' => ['token_validation'],
        ],
        'request.with_audit' => [
            'middleware' => ['routing', 'error-handler', 'session-start', 'auth-guard', 'authorization', 'audit-writer'],
            'auth' => 'session',
            'outputs' => ['json_serialization'],
            'side_effects' => ['session_read_write', 'audit_log_write_hmac'],
        ],
        'request.compliance_event' => [
            'middleware' => ['routing', 'error-handler', 'session-start', 'auth-guard', 'authorization', 'audit-writer', 'compliance-dispatcher'],
            'auth' => 'session',
            'outputs' => ['json_serialization'],
            'side_effects' => ['session_read_write', 'audit_log_write_hmac', 'compliance_event_dispatch'],
        ],
    ];

    /**
     * Validate the manifest against all semantic contracts.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<string> List of violations (empty = valid)
     */
    public function validate(array $manifest): array
    {
        $violations = [];

        if (!array_key_exists('request_classes', $manifest)) {
            $violations[] = 'Manifest missing "request_classes" key';
            return $violations;
        }

        /** @var array<string, array<string, mixed>> $requestClasses */
        $requestClasses = $manifest['request_classes'];

        foreach (self::CONTRACTS as $requestClass => $contract) {
            if (!array_key_exists($requestClass, $requestClasses)) {
                $violations[] = sprintf('Missing request class: %s', $requestClass);
                continue;
            }

            $manifestEntry = $requestClasses[$requestClass];

            // Validate required middleware
            /** @var list<string> $manifestMiddleware */
            $manifestMiddleware = $manifestEntry['middleware'] ?? [];
            $missingMiddleware = array_diff($contract['middleware'], $manifestMiddleware);

            if ($missingMiddleware !== []) {
                $violations[] = sprintf(
                    '%s: missing required middleware: %s',
                    $requestClass,
                    implode(', ', $missingMiddleware),
                );
            }

            // Validate auth type
            /** @var string|null $manifestAuth */
            $manifestAuth = $manifestEntry['auth'] ?? null;
            if ($manifestAuth !== $contract['auth']) {
                $violations[] = sprintf(
                    '%s: expected auth type "%s", got "%s"',
                    $requestClass,
                    $contract['auth'] ?? 'null',
                    (string) ($manifestAuth ?? 'null'),
                );
            }

            // Validate required outputs
            /** @var list<string> $manifestOutputs */
            $manifestOutputs = $manifestEntry['required_outputs'] ?? [];
            $missingOutputs = array_diff($contract['outputs'], $manifestOutputs);

            if ($missingOutputs !== []) {
                $violations[] = sprintf(
                    '%s: missing required outputs: %s',
                    $requestClass,
                    implode(', ', $missingOutputs),
                );
            }

            // Validate required side effects
            /** @var list<string> $manifestSideEffects */
            $manifestSideEffects = $manifestEntry['required_side_effects'] ?? [];
            $missingSideEffects = array_diff($contract['side_effects'], $manifestSideEffects);

            if ($missingSideEffects !== []) {
                $violations[] = sprintf(
                    '%s: missing required side-effects: %s',
                    $requestClass,
                    implode(', ', $missingSideEffects),
                );
            }
        }

        return $violations;
    }

    /**
     * Validate and throw on violation.
     *
     * @param array<string, mixed> $manifest
     */
    public function assertValid(array $manifest): void
    {
        $violations = $this->validate($manifest);

        if ($violations !== []) {
            throw new RuntimeException(sprintf(
                "Benchmark pipeline manifest contract violations:\n- %s",
                implode("\n- ", $violations),
            ));
        }
    }
}
