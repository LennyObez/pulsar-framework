<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Forms\HoneypotDetector;

#[CoversClass(HoneypotDetector::class)]
final class HoneypotDetectorTest extends TestCase
{
    #[Test]
    public function detectReturnsSpamWhenHoneypotFilledInMeta(): void
    {
        $detector = new HoneypotDetector();
        $result = $detector->detect([], ['_hp_field' => 'bot value']);

        self::assertTrue($result->isSpam);
        self::assertSame(10.0, $result->score);
        self::assertSame('Honeypot field filled', $result->reason);
    }

    #[Test]
    public function detectReturnsSpamWhenHoneypotFilledInData(): void
    {
        $detector = new HoneypotDetector();
        $result = $detector->detect(['_hp_field' => 'bot value'], []);

        self::assertTrue($result->isSpam);
        self::assertSame(10.0, $result->score);
        self::assertSame('Honeypot field filled', $result->reason);
    }

    #[Test]
    public function detectReturnsCleanWhenHoneypotEmpty(): void
    {
        $detector = new HoneypotDetector();
        $result = $detector->detect([], ['_hp_field' => '']);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function detectReturnsCleanWhenHoneypotMissing(): void
    {
        $detector = new HoneypotDetector();
        $result = $detector->detect([], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function detectReturnsCleanWhenHoneypotIsWhitespace(): void
    {
        $detector = new HoneypotDetector();
        $result = $detector->detect([], ['_hp_field' => '   ']);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function detectUsesCustomFieldName(): void
    {
        $detector = new HoneypotDetector(fieldName: '_custom_hp');
        $result = $detector->detect([], ['_custom_hp' => 'filled']);

        self::assertTrue($result->isSpam);
        self::assertSame(10.0, $result->score);
    }

    #[Test]
    public function detectPrefersMetaOverData(): void
    {
        $detector = new HoneypotDetector();
        // Meta is empty string (clean), data is filled (spam) — meta wins
        $result = $detector->detect(['_hp_field' => 'filled'], ['_hp_field' => '']);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    #[DataProvider('nonStringValueProvider')]
    public function detectIgnoresNonStringValues(mixed $value): void
    {
        $detector = new HoneypotDetector();
        $result = $detector->detect([], ['_hp_field' => $value]);

        self::assertFalse($result->isSpam);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringValueProvider(): iterable
    {
        yield 'integer' => [42];
        yield 'boolean true' => [true];
        yield 'array' => [['spam']];
        yield 'null' => [null];
    }
}
