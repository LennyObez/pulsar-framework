<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Represents the delimiter-balance state of a partially entered expression.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MultiLineState
{
    public function __construct(
        public int $braces = 0,
        public int $parentheses = 0,
        public int $brackets = 0,
        public bool $inSingleQuote = false,
        public bool $inDoubleQuote = false,
        public bool $inHeredoc = false,
        public bool $trailingBackslash = false,
    ) {}

    /**
     * Whether all delimiters are balanced and no strings are open.
     */
    #[NoDiscard]
    public function isComplete(): bool
    {
        return $this->braces <= 0
            && $this->parentheses <= 0
            && $this->brackets <= 0
            && !$this->inSingleQuote
            && !$this->inDoubleQuote
            && !$this->inHeredoc
            && !$this->trailingBackslash;
    }

    /**
     * Human-readable reason why the input is incomplete.
     */
    #[NoDiscard]
    public function reason(): string
    {
        if ($this->inSingleQuote) {
            return 'Unclosed single-quoted string';
        }

        if ($this->inDoubleQuote) {
            return 'Unclosed double-quoted string';
        }

        if ($this->inHeredoc) {
            return 'Unclosed heredoc/nowdoc';
        }

        if ($this->braces > 0) {
            return "Unclosed brace ({$this->braces} open)";
        }

        if ($this->parentheses > 0) {
            return "Unclosed parenthesis ({$this->parentheses} open)";
        }

        if ($this->brackets > 0) {
            return "Unclosed bracket ({$this->brackets} open)";
        }

        if ($this->trailingBackslash) {
            return 'Line continuation (trailing backslash)';
        }

        return 'Complete';
    }
}
