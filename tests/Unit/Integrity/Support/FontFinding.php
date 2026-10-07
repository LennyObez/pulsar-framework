<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

/**
 * One thing wrong with the bundled fonts.
 *
 * The rule is an identifier rather than a sentence because the negative tests assert on
 * it: a test that matched the message would pass on any finding whose wording happened to
 * overlap, which is how a negative test ends up guarding a rule other than its own.
 */
final readonly class FontFinding
{
    /**
     * @param string $rule    stable identifier, e.g. `bundle/sha256`
     * @param string $subject the file or family the finding is about
     * @param string $message what is wrong, in the words a reviewer would use
     */
    public function __construct(
        public string $rule,
        public string $subject,
        public string $message,
    ) {}

    public function describe(): string
    {
        return '[' . $this->rule . '] ' . $this->subject . ': ' . $this->message;
    }
}
