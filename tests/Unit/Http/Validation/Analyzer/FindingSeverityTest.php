<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Analyzer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;

#[CoversClass(FindingSeverity::class)]
final class FindingSeverityTest extends TestCase
{
    #[Test]
    public function hasThreeSeverityLevels(): void
    {
        $cases = FindingSeverity::cases();

        self::assertCount(3, $cases);
        self::assertSame('info', FindingSeverity::Info->value);
        self::assertSame('warning', FindingSeverity::Warning->value);
        self::assertSame('critical', FindingSeverity::Critical->value);
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(FindingSeverity::Info, FindingSeverity::from('info'));
        self::assertSame(FindingSeverity::Warning, FindingSeverity::from('warning'));
        self::assertSame(FindingSeverity::Critical, FindingSeverity::from('critical'));
    }
}
