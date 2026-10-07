<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\HoneypotDetector;

#[CoversClass(HoneypotDetector::class)]
final class HoneypotDetectorTest extends TestCase
{
    #[Test]
    public function nameReturnsHoneypot(): void
    {
        $detector = new HoneypotDetector();
        self::assertSame('honeypot', $detector->name());
    }

    #[Test]
    public function passesWhenHoneypotFieldIsEmpty(): void
    {
        $detector = new HoneypotDetector();
        $context = new AntiSpamContext(
            body: 'Legitimate comment',
            ipHash: 'ip',
            formFields: ['website_url' => ''],
        );

        $result = $detector->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesWhenHoneypotFieldIsMissing(): void
    {
        $detector = new HoneypotDetector();
        $context = new AntiSpamContext(
            body: 'Legitimate comment',
            ipHash: 'ip',
            formFields: ['body' => 'text'],
        );

        $result = $detector->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsWhenHoneypotFieldIsFilled(): void
    {
        $detector = new HoneypotDetector();
        $context = new AntiSpamContext(
            body: 'spam content',
            ipHash: 'ip',
            formFields: ['website_url' => 'http://spam.example.com'],
        );

        $result = $detector->check($context);

        self::assertFalse($result->passed);
        self::assertSame(50, $result->score);
        self::assertStringContainsString('Honeypot', $result->reason ?? '');
    }

    #[Test]
    public function customFieldNameIsRespected(): void
    {
        $detector = new HoneypotDetector('fax_number');
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            formFields: ['fax_number' => '555-1234'],
        );

        $result = $detector->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function passesWhenCustomFieldIsEmptyButDefaultIsFilled(): void
    {
        $detector = new HoneypotDetector('fax_number');
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            formFields: ['website_url' => 'filled', 'fax_number' => ''],
        );

        $result = $detector->check($context);

        self::assertTrue($result->passed);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringHoneypotValueProvider(): iterable
    {
        yield 'integer' => [42];
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'array' => [['nested']];
    }

    #[Test]
    #[DataProvider('nonStringHoneypotValueProvider')]
    public function passesWhenHoneypotValueIsNotString(mixed $value): void
    {
        $detector = new HoneypotDetector();
        $context = new AntiSpamContext(
            body: 'test',
            ipHash: 'ip',
            formFields: ['website_url' => $value],
        );

        $result = $detector->check($context);

        self::assertTrue($result->passed);
    }
}
