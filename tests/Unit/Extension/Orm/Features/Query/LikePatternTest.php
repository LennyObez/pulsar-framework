<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LikePattern;

#[CoversClass(LikePattern::class)]
final class LikePatternTest extends TestCase
{
    #[Test]
    public function startsWithAppendsWildcard(): void
    {
        $pattern = LikePattern::startsWith('John');

        self::assertSame('John%', $pattern->toString());
    }

    #[Test]
    public function endsWithPrependsWildcard(): void
    {
        $pattern = LikePattern::endsWith('.com');

        self::assertSame('%.com', $pattern->toString());
    }

    #[Test]
    public function containsWrapsWithWildcards(): void
    {
        $pattern = LikePattern::contains('test');

        self::assertSame('%test%', $pattern->toString());
    }

    #[Test]
    public function rawPassesThroughUnescaped(): void
    {
        $pattern = LikePattern::raw('%custom_pattern%');

        self::assertSame('%custom_pattern%', $pattern->toString());
    }

    #[Test]
    public function startsWithEscapesPercent(): void
    {
        $pattern = LikePattern::startsWith('100%');

        self::assertSame('100\\%%', $pattern->toString());
    }

    #[Test]
    public function containsEscapesUnderscore(): void
    {
        $pattern = LikePattern::contains('user_name');

        self::assertSame('%user\\_name%', $pattern->toString());
    }

    #[Test]
    public function endsWithEscapesBackslash(): void
    {
        $pattern = LikePattern::endsWith('C:\\path');

        self::assertSame('%C:\\\\path', $pattern->toString());
    }

    #[Test]
    public function containsEscapesMultipleSpecialChars(): void
    {
        $pattern = LikePattern::contains('100%_value\\end');

        self::assertSame('%100\\%\\_value\\\\end%', $pattern->toString());
    }
}
