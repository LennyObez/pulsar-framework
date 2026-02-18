<?php

declare(strict_types=1);

namespace Pulsar\Build;

use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Result of verifying all artifacts against a build manifest.
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
        $entries = [];

        foreach ($this->entries as $key => $status) {
            $entries[$key] = $status->value;
        }

        return [
            'passed' => $this->passed,
            'entries' => $entries,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $entries = [];

        if (isset($data['entries']) && is_array($data['entries'])) {
            foreach ($data['entries'] as $key => $statusValue) {
                if (is_string($key) && is_string($statusValue)) {
                    $status = VerificationStatus::tryFrom($statusValue);

                    if ($status !== null) {
                        $entries[$key] = $status;
                    }
                }
            }
        }

        /** @var list<string> $errors */
        $errors = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];

        return new self(
            passed: isset($data['passed']) && $data['passed'] === true,
            entries: $entries,
            errors: $errors,
        );
    }
}
