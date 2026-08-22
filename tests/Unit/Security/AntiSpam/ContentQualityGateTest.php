<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\ContentQualityGate;

#[CoversClass(ContentQualityGate::class)]
final class ContentQualityGateTest extends TestCase
{
    #[Test]
    public function nameReturnsContentQuality(): void
    {
        $gate = new ContentQualityGate();
        self::assertSame('content_quality', $gate->name());
    }

    #[Test]
    public function passesForNormalContent(): void
    {
        $gate = new ContentQualityGate();
        $context = new AntiSpamContext(
            body: 'This is a perfectly normal and reasonable comment about the article.',
            ipHash: 'ip',
        );

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForTooShortContent(): void
    {
        $gate = new ContentQualityGate(minLength: 10);
        $context = new AntiSpamContext(body: 'Hi', ipHash: 'ip');

        $result = $gate->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('too short', $result->reason ?? '');
    }

    #[Test]
    public function passesAtExactMinLength(): void
    {
        $gate = new ContentQualityGate(minLength: 10);
        $context = new AntiSpamContext(body: '1234567890', ipHash: 'ip');

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForAllCapsContent(): void
    {
        $gate = new ContentQualityGate(maxUppercaseRatio: 0.8);
        $context = new AntiSpamContext(
            body: 'THIS IS ALL CAPS AND VERY LOUD SHOUTING IN THE COMMENTS',
            ipHash: 'ip',
        );

        $result = $gate->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('uppercase', $result->reason ?? '');
    }

    #[Test]
    public function passesForMixedCaseContent(): void
    {
        $gate = new ContentQualityGate(maxUppercaseRatio: 0.8);
        $context = new AntiSpamContext(
            body: 'This Has Some Capitals But Is Mostly Normal Text for reading.',
            ipHash: 'ip',
        );

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForExcessiveRepetition(): void
    {
        $gate = new ContentQualityGate(maxRepeatedCharRatio: 0.5);
        $context = new AntiSpamContext(
            body: 'aaaaaaaaaaaaaaaaaaaabbbbbbbbbbbbbbbb end',
            ipHash: 'ip',
        );

        $result = $gate->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('repeated', $result->reason ?? '');
    }

    #[Test]
    public function passesForNormalRepetition(): void
    {
        $gate = new ContentQualityGate(maxRepeatedCharRatio: 0.5);
        $context = new AntiSpamContext(
            body: 'This comment has normal characters and no excessive repetition at all.',
            ipHash: 'ip',
        );

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function uppercaseCheckIgnoresNonLetterCharacters(): void
    {
        $gate = new ContentQualityGate(maxUppercaseRatio: 0.8);
        // Numbers and punctuation should not affect the uppercase ratio
        $context = new AntiSpamContext(
            body: '12345 !!!!! 67890 ????? This is normal text.',
            ipHash: 'ip',
        );

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function uppercaseCheckSkipsVeryShortAlphaContent(): void
    {
        $gate = new ContentQualityGate(maxUppercaseRatio: 0.8);
        // Only 2 letters — should skip the uppercase check
        $context = new AntiSpamContext(body: 'OK 12345678', ipHash: 'ip');

        $result = $gate->check($context);

        self::assertTrue($result->passed);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function unicodeContentProvider(): iterable
    {
        yield 'accented lowercase' => ['Ceci est un commentaire en français avec des accents.', true];
        yield 'CJK characters' => ['これは日本語のコメントです。十分な長さがあります。', true];
        yield 'emoji with text' => ['Great article! Really enjoyed reading it today.', true];
    }

    #[Test]
    #[DataProvider('unicodeContentProvider')]
    public function handlesUnicodeContent(string $body, bool $shouldPass): void
    {
        $gate = new ContentQualityGate();
        $context = new AntiSpamContext(body: $body, ipHash: 'ip');

        $result = $gate->check($context);

        self::assertSame($shouldPass, $result->passed);
    }
}
