<?php

declare(strict_types=1);

namespace Pulsar\I18n\Linter;

use Pulsar\Api\Internal;

use function count;

/**
 * Result of translation catalog linting.
 */
#[Internal]
readonly class LintResult
{
    /**
     * @param list<array{severity: LintSeverity, key: string, locale: string, domain: string, message: string}> $issues
     */
    public function __construct(
        public array $issues,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === LintSeverity::Error) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->issues);
    }
}
