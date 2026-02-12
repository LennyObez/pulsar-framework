<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\DeviceType;
use Pulsar\Extension\Analytics\Internal\Service\UserAgentParser;

final class UserAgentParserTest extends TestCase
{
    private UserAgentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new UserAgentParser();
    }

    #[Test]
    public function emptyUserAgentReturnsUnknown(): void
    {
        $info = $this->parser->parse('');

        self::assertSame('Unknown', $info->browser);
        self::assertSame('Unknown', $info->os);
        self::assertSame(DeviceType::Unknown, $info->deviceType);
    }

    #[Test]
    public function chromeOnWindowsDesktop(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.130 Safari/537.36',
        );

        self::assertSame('Chrome', $info->browser);
        self::assertSame('120.0.6099.130', $info->browserVersion);
        self::assertSame('Windows', $info->os);
        self::assertSame('10', $info->osVersion);
        self::assertSame(DeviceType::Desktop, $info->deviceType);
    }

    #[Test]
    public function firefoxOnLinuxDesktop(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
        );

        self::assertSame('Firefox', $info->browser);
        self::assertSame('121.0', $info->browserVersion);
        self::assertSame('Linux', $info->os);
        self::assertSame(DeviceType::Desktop, $info->deviceType);
    }

    #[Test]
    public function safariOnMacOs(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        );

        self::assertSame('Safari', $info->browser);
        self::assertSame('17.2', $info->browserVersion);
        self::assertSame('macOS', $info->os);
        self::assertSame('10.15.7', $info->osVersion);
        self::assertSame(DeviceType::Desktop, $info->deviceType);
    }

    #[Test]
    public function chromeOnAndroidMobile(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
        );

        self::assertSame('Chrome', $info->browser);
        self::assertSame('Android', $info->os);
        self::assertSame('14', $info->osVersion);
        self::assertSame(DeviceType::Mobile, $info->deviceType);
    }

    #[Test]
    public function safariOnIphone(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
        );

        self::assertSame('Safari', $info->browser);
        self::assertSame('iOS', $info->os);
        self::assertSame('17.2', $info->osVersion);
        self::assertSame(DeviceType::Mobile, $info->deviceType);
    }

    #[Test]
    public function safariOnIpad(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
        );

        self::assertSame('iOS', $info->os);
        self::assertSame(DeviceType::Tablet, $info->deviceType);
    }

    #[Test]
    public function edgeBrowserDetected(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.91',
        );

        self::assertSame('Edge', $info->browser);
        self::assertSame('120.0.2210.91', $info->browserVersion);
    }

    #[Test]
    public function operaBrowserDetected(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
        );

        self::assertSame('Opera', $info->browser);
        self::assertSame('106.0.0.0', $info->browserVersion);
    }

    #[Test]
    public function samsungInternetDetected(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
        );

        self::assertSame('Samsung Internet', $info->browser);
        self::assertSame('23.0', $info->browserVersion);
    }

    #[Test]
    public function androidTabletWithoutMobileIsTablet(): void
    {
        $info = $this->parser->parse(
            'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Safari/537.36',
        );

        self::assertSame(DeviceType::Tablet, $info->deviceType);
    }

    #[Test]
    public function windowsVersionMapping(): void
    {
        $win7 = $this->parser->parse('Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');
        self::assertSame('7', $win7->osVersion);

        $win81 = $this->parser->parse('Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');
        self::assertSame('8.1', $win81->osVersion);
    }
}
