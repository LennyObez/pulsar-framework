<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\Risk\BotScoreSignalProvider;
use Pulsar\Security\ThreatDetection\BotDetector;

#[CoversClass(BotScoreSignalProvider::class)]
final class BotScoreSignalProviderTest extends TestCase
{
    #[Test]
    public function normalisesBotScoreIntoZeroToOneRange(): void
    {
        $provider = new BotScoreSignalProvider(new BotDetector());

        // A known bot User-Agent ('curl') scores 50/100 in BotDetector.
        $signal = $provider->evaluate(new ServerRequest(method: 'GET', uri: '/', headers: ['User-Agent' => 'curl/8.4.0']));

        self::assertSame('bot_detector', $signal->source);
        self::assertGreaterThan(0.0, $signal->score);
        self::assertLessThanOrEqual(1.0, $signal->score);
    }

    #[Test]
    public function scoresBotsHigherThanBrowsers(): void
    {
        $provider = new BotScoreSignalProvider(new BotDetector());

        $botSignal = $provider->evaluate(new ServerRequest(method: 'GET', uri: '/', headers: ['User-Agent' => 'python-requests/2.31']));
        $browserSignal = $provider->evaluate(new ServerRequest(method: 'GET', uri: '/', headers: [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'text/html',
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'gzip',
            'Connection' => 'keep-alive',
        ]));

        self::assertGreaterThan($browserSignal->score, $botSignal->score);
    }
}
