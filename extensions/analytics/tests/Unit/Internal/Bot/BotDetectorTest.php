<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Bot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;

final class BotDetectorTest extends TestCase
{
    private BotDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new BotDetector();
    }

    #[Test]
    public function emptyUserAgentIsBot(): void
    {
        self::assertTrue($this->detector->isBot(''));
    }

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
    public function realBrowserWithAcceptLanguageIsNotBot(): void
    {
        self::assertFalse($this->detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ['accept-language' => 'en-US,en;q=0.9'],
        ));
    }

    #[Test]
    public function realBrowserWithoutAcceptLanguageIsBot(): void
    {
        self::assertTrue($this->detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            [],
        ));
    }

    #[Test]
    public function realBrowserWithEmptyAcceptLanguageIsBot(): void
    {
        self::assertTrue($this->detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ['accept-language' => ''],
        ));
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
}
