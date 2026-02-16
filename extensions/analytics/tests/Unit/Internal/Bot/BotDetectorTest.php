<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Bot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;

#[CoversClass(BotDetector::class)]
final class BotDetectorTest extends TestCase
{
    private BotDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new BotDetector();
    }

    // ------------------------------------------------------------------
    // Tier 1: Empty UA is always bot
    // ------------------------------------------------------------------

    #[Test]
    public function emptyUserAgentIsBot(): void
    {
        self::assertTrue($this->detector->isBot(''));
    }

    #[Test]
    public function emptyUserAgentIsBotEvenWithAcceptLanguage(): void
    {
        self::assertTrue($this->detector->isBot('', ['accept-language' => 'en-US']));
    }

    // ------------------------------------------------------------------
    // Tier 2: Known bot UA patterns (always flagged regardless of headers)
    // ------------------------------------------------------------------

    #[Test]
    public function googlebotIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
    }

    #[Test]
    public function bingbotIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'));
    }

    #[Test]
    public function curlIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('curl/7.88.1'));
    }

    #[Test]
    public function pythonRequestsIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('python-requests/2.28.0'));
    }

    #[Test]
    public function wgetIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('Wget/1.21'));
    }

    #[Test]
    public function spiderPatternIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('CustomSpider/1.0'));
    }

    #[Test]
    public function crawlerPatternIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('SomeCrawler/2.0'));
    }

    #[Test]
    public function goHttpClientIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('Go-http-client/2.0'));
    }

    #[Test]
    public function javaClientIsDetected(): void
    {
        self::assertTrue($this->detector->isBot('Java/1.8.0_351'));
    }

    #[Test]
    public function knownBotFlaggedEvenWithAcceptLanguage(): void
    {
        self::assertTrue($this->detector->isBot(
            'Mozilla/5.0 (compatible; Googlebot/2.1)',
            ['accept-language' => 'en-US,en;q=0.9'],
        ));
    }

    // ------------------------------------------------------------------
    // Tier 3: Header heuristic (default non-strict mode)
    // ------------------------------------------------------------------

    #[Test]
    public function realBrowserWithAcceptLanguageIsNotBot(): void
    {
        self::assertFalse($this->detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ['accept-language' => 'en-US,en;q=0.9'],
        ));
    }

    #[Test]
    public function browserUaWithoutAcceptLanguageIsNotBotInDefaultMode(): void
    {
        // Chrome-like UA without Accept-Language should NOT be flagged in default mode
        // because the UA contains recognized browser tokens
        self::assertFalse($this->detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        ));
    }

    #[Test]
    public function browserUaWithEmptyAcceptLanguageIsNotBotInDefaultMode(): void
    {
        self::assertFalse($this->detector->isBot(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ['accept-language' => ''],
        ));
    }

    #[Test]
    public function apiClientWithoutAcceptLanguageIsNotBotInDefaultMode(): void
    {
        // A custom API client that has no browser tokens and no Accept-Language
        // IS flagged because it looks suspicious (no browser token + no Accept-Language)
        self::assertTrue($this->detector->isBot(
            'MyCustomApp/1.0',
        ));
    }

    #[Test]
    public function apiClientWithAcceptLanguageIsNotBot(): void
    {
        // A custom API client that provides Accept-Language is not flagged
        self::assertFalse($this->detector->isBot(
            'MyCustomApp/1.0',
            ['accept-language' => 'en'],
        ));
    }

    #[Test]
    public function firefoxUaWithoutAcceptLanguageIsNotBotInDefaultMode(): void
    {
        self::assertFalse($this->detector->isBot(
            'Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/119.0',
        ));
    }

    #[Test]
    public function edgeUaWithoutAcceptLanguageIsNotBotInDefaultMode(): void
    {
        self::assertFalse($this->detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36 Edge/120',
        ));
    }

    #[Test]
    public function operaUaWithoutAcceptLanguageIsNotBotInDefaultMode(): void
    {
        self::assertFalse($this->detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36 Opera/90',
        ));
    }

    // ------------------------------------------------------------------
    // Strict mode
    // ------------------------------------------------------------------

    #[Test]
    public function strictModeFlagsMissingAcceptLanguageAlone(): void
    {
        $strictDetector = new BotDetector(strictMode: true);

        // Browser UA without Accept-Language is flagged in strict mode
        self::assertTrue($strictDetector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        ));
    }

    #[Test]
    public function strictModeFlagsEmptyAcceptLanguage(): void
    {
        $strictDetector = new BotDetector(strictMode: true);

        self::assertTrue($strictDetector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ['accept-language' => ''],
        ));
    }

    #[Test]
    public function strictModeAllowsBrowserWithAcceptLanguage(): void
    {
        $strictDetector = new BotDetector(strictMode: true);

        self::assertFalse($strictDetector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ['accept-language' => 'en-US'],
        ));
    }

    #[Test]
    public function strictModeStillDetectsKnownBots(): void
    {
        $strictDetector = new BotDetector(strictMode: true);

        self::assertTrue($strictDetector->isBot('Googlebot/2.1'));
    }

    #[Test]
    public function strictModeStillDetectsEmptyUa(): void
    {
        $strictDetector = new BotDetector(strictMode: true);

        self::assertTrue($strictDetector->isBot(''));
    }

    // ------------------------------------------------------------------
    // SPA / API client scenarios (key regression tests)
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('spaClientProvider')]
    public function spaClientsWithBrowserTokensAreNotFlaggedWithoutAcceptLanguage(string $userAgent): void
    {
        // SPA frontends making fetch() calls may not send Accept-Language
        self::assertFalse($this->detector->isBot($userAgent));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spaClientProvider(): iterable
    {
        yield 'Chrome SPA' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120 Safari/537.36'];
        yield 'Firefox SPA' => ['Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/119.0'];
        yield 'Safari SPA' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15'];
        yield 'Edge SPA' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Edge/120.0.0.0'];
    }

    #[Test]
    public function defaultModeConstructsWithoutArguments(): void
    {
        $detector = new BotDetector();

        // Known bot still detected
        self::assertTrue($detector->isBot('curl/8.0'));
        // Browser UA without Accept-Language not flagged
        self::assertFalse($detector->isBot(
            'Mozilla/5.0 Chrome/120 Safari/537.36',
        ));
    }
}
