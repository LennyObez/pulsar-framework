<?php

declare(strict_types=1);

namespace Pulsar\Build;

use Pulsar\Api\Api;

use function array_map;
use function is_string;

/**
 * Result of verifying all artifacts against a build manifest.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VerificationResult
{
    /**
     * @param bool $passed Whether all artifacts passed verification
     * @param array<string, VerificationStatus> $entries Artifact key → verification status
     * @param list<string> $errors Human-readable error descriptions
     */
    public function __construct(
        public bool $passed,
        public array $entries,
        public array $errors = [],
    ) {}

    /**
     * Create a passing result.
     *
     * @param array<string, VerificationStatus> $entries
     */
    public static function pass(array $entries): self
    {
        return new self(passed: true, entries: $entries);
    }

    /**
     * Create a failing result.
     *
     * @param array<string, VerificationStatus> $entries
     * @param list<string> $errors
     */
    public static function fail(array $entries, array $errors): self
    {
        return new self(passed: false, entries: $entries, errors: $errors);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $entries = array_map(static fn(VerificationStatus $status): string => $status->value, $this->entries);

        return [
            'passed' => $this->passed,
            'entries' => $entries,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param array{
     *     passed?: bool,
     *     entries?: array<string, string>,
     *     errors?: list<string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $entries = [];

        foreach ($data['entries'] ?? [] as $key => $statusValue) {
            if (is_string($key)) {
                $status = VerificationStatus::tryFrom($statusValue);

                if ($status !== null) {
                    $entries[$key] = $status;
                }
            }
        }

        return new self(
            passed: ($data['passed'] ?? false) === true,
            entries: $entries,
            errors: $data['errors'] ?? [],
        );
    }
}
