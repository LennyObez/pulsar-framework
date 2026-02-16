<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Bot;

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

    #[Test]
    #[DataProvider('knownBotUserAgents')]
    public function detectsKnownBots(string $userAgent): void
    {
        self::assertTrue(
            $this->detector->isBot($userAgent, ['accept-language' => 'en-US']),
            "Expected '{$userAgent}' to be detected as a bot",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function knownBotUserAgents(): iterable
    {
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'];
        yield 'curl' => ['curl/7.68.0'];
        yield 'wget' => ['Wget/1.21'];
        yield 'python-requests' => ['python-requests/2.28.1'];
        yield 'Go HTTP client' => ['Go-http-client/1.1'];
        yield 'Baidu Spider' => ['Mozilla/5.0 (compatible; Baiduspider/2.0)'];
        yield 'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0)'];
        yield 'DuckDuckBot' => ['DuckDuckBot/1.1'];
        yield 'Java client' => ['Java/17.0.1'];
        yield 'Generic spider' => ['Mozilla/5.0 (compatible; MySpider/1.0)'];
        yield 'Generic crawler' => ['Mozilla/5.0 (compatible; MyCrawler/1.0)'];
    }

    #[Test]
    #[DataProvider('realBrowserUserAgents')]
    public function doesNotDetectRealBrowsersAsBots(string $userAgent): void
    {
        self::assertFalse(
            $this->detector->isBot($userAgent, ['accept-language' => 'en-US,en;q=0.9']),
            "Expected '{$userAgent}' NOT to be detected as a bot",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realBrowserUserAgents(): iterable
    {
        yield 'Chrome Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'];
        yield 'Firefox Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'];
        yield 'Safari macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15'];
        yield 'Edge Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'];
        yield 'Chrome Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'];
        yield 'Safari iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'];
    }

    #[Test]
    public function emptyUserAgentIsAlwaysABot(): void
    {
        self::assertTrue($this->detector->isBot('', ['accept-language' => 'en-US']));
    }

    #[Test]
    public function browserUaWithoutAcceptLanguageIsNotBotInDefaultMode(): void
    {
        $normalBrowserUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // Default mode: browser UA without Accept-Language is NOT flagged
        self::assertFalse($this->detector->isBot($normalBrowserUa, []));
        self::assertFalse($this->detector->isBot($normalBrowserUa, ['accept-language' => '']));
    }

    #[Test]
    public function strictModeFlagsBrowserUaWithoutAcceptLanguage(): void
    {
        $strictDetector = new BotDetector(strictMode: true);
        $normalBrowserUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // Strict mode: missing Accept-Language alone flags as bot
        self::assertTrue($strictDetector->isBot($normalBrowserUa, []));
        self::assertTrue($strictDetector->isBot($normalBrowserUa, ['accept-language' => '']));
    }

    #[Test]
    public function nonBrowserUaWithoutAcceptLanguageIsBotInDefaultMode(): void
    {
        // Custom API client without browser tokens and without Accept-Language
        self::assertTrue($this->detector->isBot('MyCustomApp/1.0', []));
    }

    #[Test]
    public function nonBrowserUaWithAcceptLanguageIsNotBot(): void
    {
        // Custom API client with Accept-Language is fine
        self::assertFalse($this->detector->isBot('MyCustomApp/1.0', ['accept-language' => 'en']));
    }

    #[Test]
    public function realBrowserWithAcceptLanguageIsNotABot(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        self::assertFalse($this->detector->isBot($ua, ['accept-language' => 'en-US']));
    }
}
