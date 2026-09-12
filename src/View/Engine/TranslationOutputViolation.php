<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Pulsar\Api\Internal;

/**
 * One place in a template where a translated string would reach the output
 * without being escaped.
 *
 * Produced only by {@see TranslationOutputGuard}, which is the component that
 * knows which compiled form escapes and which does not. Consumers report it;
 * they never decide it.
 */
#[Internal(reason: 'Compiler diagnostic detail')]
final readonly class TranslationOutputViolation
{
    /**
     * @param int $line 1-based line in the template source
     * @param string $construct The offending source text, as written
     * @param string $reason Why it is unsafe and what to write instead
     */
    public function __construct(
        public int $line,
        public string $construct,
        public string $reason,
    ) {}

    /**
     * Render the violation as a single diagnostic line.
     */
    public function describe(): string
    {
        return 'line ' . $this->line . ': ' . $this->construct . ' — ' . $this->reason;
    }
}
