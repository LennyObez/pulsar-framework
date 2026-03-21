<?php

declare(strict_types=1);

namespace Pulsar\I18n\Linter;

use Pulsar\Api\Internal;

use function count;

/**
 * Result of translation catalog linting.
 */
#[Internal]
final readonly class LintResult
{
    /**
     * @param list<array{severity: LintSeverity, key: string, locale: string, domain: string, message: string}> $issues
     */
    public function __construct(
        public array $issues,
    ) {}

    public function hasErrors(): bool
    {
        return array_any($this->issues, static fn(array $issue): bool => $issue['severity'] === LintSeverity::Error);
    }

    public function count(): int
    {
        return count($this->issues);
    }
}
