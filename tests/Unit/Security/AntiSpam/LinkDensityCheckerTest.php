<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\LinkDensityChecker;

#[CoversClass(LinkDensityChecker::class)]
final class LinkDensityCheckerTest extends TestCase
{
    #[Test]
    public function nameReturnsLinkDensity(): void
    {
        $checker = new LinkDensityChecker();
        self::assertSame('link_density', $checker->name());
    }

    #[Test]
    public function passesForPlainText(): void
    {
        $checker = new LinkDensityChecker();
        $context = new AntiSpamContext(
            body: 'This is a perfectly normal comment without any links at all.',
            ipHash: 'ip',
        );

        $result = $checker->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesForEmptyBody(): void
    {
        $checker = new LinkDensityChecker();
        $context = new AntiSpamContext(body: '', ipHash: 'ip');

        $result = $checker->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesForAcceptableLinkRatio(): void
    {
        $checker = new LinkDensityChecker(0.3);
        // Body is ~100 chars, URL is ~20 chars = 20% density < 30%
        $body = 'This is a comment with a link https://example.com and a lot of other text filling up space here.';
        $context = new AntiSpamContext(body: $body, ipHash: 'ip');

        $result = $checker->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForExcessiveLinkDensity(): void
    {
        $checker = new LinkDensityChecker(0.3);
        // Body is predominantly URLs
        $body = 'https://spam1.example.com https://spam2.example.com https://spam3.example.com buy now';
        $context = new AntiSpamContext(body: $body, ipHash: 'ip');

        $result = $checker->check($context);

        self::assertFalse($result->passed);
        self::assertSame(30, $result->score);
        self::assertStringContainsString('Link density', $result->reason ?? '');
    }

    #[Test]
    public function customThresholdIsRespected(): void
    {
        // Very strict: only 10% links allowed
        $checker = new LinkDensityChecker(0.1);
        $body = 'Check out https://example.com for more information about this topic.';
        $context = new AntiSpamContext(body: $body, ipHash: 'ip');

        $result = $checker->check($context);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function handlesHttpsAndHttpUrls(): void
    {
        $checker = new LinkDensityChecker(0.3);
        $body = 'Visit http://old.co or https://new.co for the latest news. ' .
                'There is plenty of extra text here to ensure the ratio of URL characters ' .
                'to total characters stays well below the thirty percent threshold we set. ' .
                'This paragraph makes the body long enough.';
        $context = new AntiSpamContext(body: $body, ipHash: 'ip');

        $result = $checker->check($context);

        self::assertTrue($result->passed);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function edgeCaseBodyProvider(): iterable
    {
        yield 'single short URL in long text' => [
            str_repeat('text ', 50) . 'https://x.co',
            true,
        ];
        yield 'only a URL' => [
            'https://spam.example.com/very/long/path/to/spammy/page',
            false,
        ];
    }

    #[Test]
    #[DataProvider('edgeCaseBodyProvider')]
    public function handleEdgeCases(string $body, bool $shouldPass): void
    {
        $checker = new LinkDensityChecker(0.3);
        $context = new AntiSpamContext(body: $body, ipHash: 'ip');

        $result = $checker->check($context);

        self::assertSame($shouldPass, $result->passed);
    }
}
