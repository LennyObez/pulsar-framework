<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Linter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Linter\LintResult;
use Pulsar\I18n\Linter\LintSeverity;

#[CoversClass(LintResult::class)]
final class LintResultTest extends TestCase
{
    #[Test]
    public function hasErrorsReturnsTrueWhenErrorPresent(): void
    {
        $result = new LintResult([
            ['severity' => LintSeverity::Error, 'key' => 'k', 'locale' => 'en', 'domain' => 'messages', 'message' => 'err'],
        ]);

        self::assertTrue($result->hasErrors());
    }

    #[Test]
    public function hasErrorsReturnsFalseWithOnlyWarnings(): void
    {
        $result = new LintResult([
            ['severity' => LintSeverity::Warning, 'key' => 'k', 'locale' => 'en', 'domain' => 'messages', 'message' => 'warn'],
        ]);

        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function countReturnsIssueCount(): void
    {
        $result = new LintResult([
            ['severity' => LintSeverity::Warning, 'key' => 'a', 'locale' => 'en', 'domain' => 'messages', 'message' => 'w1'],
            ['severity' => LintSeverity::Error, 'key' => 'b', 'locale' => 'en', 'domain' => 'messages', 'message' => 'e1'],
        ]);

        self::assertSame(2, $result->count());
    }

    #[Test]
    public function emptyResultHasNoErrors(): void
    {
        $result = new LintResult([]);

        self::assertFalse($result->hasErrors());
        self::assertSame(0, $result->count());
    }
}
