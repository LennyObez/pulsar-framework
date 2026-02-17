<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Internal\BookingNumberGenerator;

#[CoversClass(BookingNumberGenerator::class)]
final class BookingNumberGeneratorTest extends TestCase
{
    public function testGenerateProducesSequentialNumbers(): void
    {
        $generator = new BookingNumberGenerator();

        $first = $generator->generate();
        $second = $generator->generate();
        $third = $generator->generate();

        $year = date('Y');
        self::assertSame("BKG-{$year}-000001", $first);
        self::assertSame("BKG-{$year}-000002", $second);
        self::assertSame("BKG-{$year}-000003", $third);
    }

    public function testGenerateFormatMatchesPattern(): void
    {
        $generator = new BookingNumberGenerator();
        $number = $generator->generate();

        self::assertMatchesRegularExpression('/^BKG-\d{4}-\d{6}$/', $number);
    }

    public function testSetSequence(): void
    {
        $generator = new BookingNumberGenerator();
        $generator->setSequence(999);

        $number = $generator->generate();

        $year = date('Y');
        self::assertSame("BKG-{$year}-001000", $number);
    }

    public function testSetSequenceToZeroRestartsFromOne(): void
    {
        $generator = new BookingNumberGenerator();
        $generator->generate(); // 1
        $generator->generate(); // 2

        $generator->setSequence(0);
        $next = $generator->generate();

        $year = date('Y');
        self::assertSame("BKG-{$year}-000001", $next);
    }
}
