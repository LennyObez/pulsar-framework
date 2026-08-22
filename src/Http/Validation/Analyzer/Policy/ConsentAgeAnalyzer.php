<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Analyzer\Policy;

use DateTimeImmutable;
use DateTimeInterface;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Analyzer\AnalyzerFinding;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\PolicyAnalyzerInterface;

use function is_int;
use function is_string;
use function sprintf;

/**
 * Advisory analyzer that flags values suggesting a subject is below the configured consent age.
 *
 * Checks numeric values (interpreted as age) and date/date-of-birth strings
 * against a configurable minimum consent age. Defaults to 13 (COPPA).
 *
 * Advisory only: not a compliance gate. Results should be reviewed by
 * qualified compliance or legal personnel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConsentAgeAnalyzer implements PolicyAnalyzerInterface
{
    public function __construct(
        private int $minimumAge = 13,
    ) {}

    /**
     * @param array<string, mixed> $data Full input data for cross-field analysis
     *
     * @return list<AnalyzerFinding>
     */
    #[Override]
    public function analyze(string $field, mixed $value, array $data): array
    {
        $age = $this->resolveAge($value);

        if ($age === null) {
            return [];
        }

        if ($age >= $this->minimumAge) {
            return [];
        }

        return [
            new AnalyzerFinding(
                severity: FindingSeverity::Warning,
                confidence: 0.7,
                field: $field,
                pattern: sprintf('Value suggests age %d, below minimum consent age %d', $age, $this->minimumAge),
                recommendation: sprintf(
                    'Field "%s" suggests the subject may be under %d. Verify parental/guardian consent requirements (e.g., COPPA).',
                    $field,
                    $this->minimumAge,
                ),
            ),
        ];
    }

    private function resolveAge(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $this->ageFromDateString($value);
    }

    private function ageFromDateString(string $value): ?int
    {
        $date = $this->parseDateString($value);

        if ($date === null) {
            return null;
        }

        $now = new DateTimeImmutable('today');

        if ($date > $now) {
            return null;
        }

        return $now->diff($date)->y;
    }

    private function parseDateString(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($date instanceof DateTimeInterface) {
            return $date->setTime(0, 0);
        }

        $date = DateTimeImmutable::createFromFormat('m/d/Y', $value);

        if ($date instanceof DateTimeInterface) {
            return $date->setTime(0, 0);
        }

        return null;
    }
}
