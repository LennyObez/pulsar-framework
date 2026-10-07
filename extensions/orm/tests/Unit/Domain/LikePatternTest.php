<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LikePattern;

final class LikePatternTest extends TestCase
{
    #[Test]
    public function startsWithAppendsWildcard(): void
    {
        $pattern = LikePattern::startsWith('john');

        self::assertSame('john%', $pattern->toString());
    }

    #[Test]
    public function endsWithPrependsWildcard(): void
    {
        $pattern = LikePattern::endsWith('.com');

        self::assertSame('%.com', $pattern->toString());
    }

    #[Test]
    public function containsWrapsBothSides(): void
    {
        $pattern = LikePattern::contains('test');

        self::assertSame('%test%', $pattern->toString());
    }

    #[Test]
    public function rawUsesPatternAsIs(): void
    {
        $pattern = LikePattern::raw('_a%b');

        self::assertSame('_a%b', $pattern->toString());
    }

    #[Test]
    public function escapesPercentInUserInput(): void
    {
        $pattern = LikePattern::contains('50%');

        self::assertSame('%50\\%%', $pattern->toString());
    }

    #[Test]
    public function escapesUnderscoreInUserInput(): void
    {
        $pattern = LikePattern::startsWith('user_name');

        self::assertSame('user\\_name%', $pattern->toString());
    }

    #[Test]
    public function escapesBackslashInUserInput(): void
    {
        $pattern = LikePattern::contains('path\\dir');

        self::assertSame('%path\\\\dir%', $pattern->toString());
    }
}
