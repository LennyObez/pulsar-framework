<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\ThreatDetection\BotDetector;
use Pulsar\Security\ThreatDetection\BotScore;

#[CoversClass(BotDetector::class)]
#[CoversClass(BotScore::class)]
final class BotDetectorTest extends TestCase
{
    private function browserRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
            ],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );
    }

    private function botRequest(string $ua = 'curl/7.68.0'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'User-Agent' => $ua,
                'Accept' => '*/*',
            ],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );
    }

    #[Test]
    public function browserRequestScoresLow(): void
    {
        $detector = new BotDetector();
        $score = $detector->analyze($this->browserRequest());

        self::assertLessThan(40, $score->score);
        self::assertTrue($score->isHuman());
        self::assertFalse($score->isBot());
    }

    #[Test]
    public function curlRequestScoresHigh(): void
    {
        $detector = new BotDetector();
        $score = $detector->analyze($this->botRequest('curl/7.68.0'));

        self::assertGreaterThanOrEqual(40, $score->score);
        self::assertTrue($score->isSuspicious());
        self::assertArrayHasKey('user_agent', $score->signals);
    }

    #[Test]
    public function noUserAgentScoresVeryHigh(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $detector = new BotDetector();
        $score = $detector->analyze($request);

        self::assertGreaterThanOrEqual(70, $score->score);
        self::assertTrue($score->isBot());
    }

    #[Test]
    public function wildcardOnlyAcceptAddsScore(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => '*/*',
                'Accept-Language' => 'en-US',
                'Accept-Encoding' => 'gzip',
                'Connection' => 'keep-alive',
            ],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $detector = new BotDetector();
        $score = $detector->analyze($request);

        self::assertArrayHasKey('accept_header', $score->signals);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function knownBotUserAgents(): iterable
    {
        yield 'curl' => ['curl/7.68.0'];
        yield 'wget' => ['Wget/1.21'];
        yield 'python-requests' => ['python-requests/2.28.0'];
        yield 'go-http-client' => ['Go-http-client/2.0'];
        yield 'postman' => ['PostmanRuntime/7.29.0'];
        yield 'googlebot' => ['Googlebot/2.1 (+http://www.google.com/bot.html)'];
    }

    #[Test]
    #[DataProvider('knownBotUserAgents')]
    public function detectsKnownBotPatterns(string $ua): void
    {
        $detector = new BotDetector();
        $score = $detector->analyze($this->botRequest($ua));

        self::assertGreaterThanOrEqual(40, $score->score);
    }

    #[Test]
    public function isBotUsesConfiguredThreshold(): void
    {
        $detector = new BotDetector(threshold: 90);

        // curl scores ~70-80, should not be bot with threshold 90
        self::assertFalse($detector->isBot($this->botRequest('curl/7.68.0')));

        // No UA scores ~70+, not bot at 90
        $noUa = new ServerRequest(method: 'GET', uri: '/', headers: [], serverParams: []);
        // With no UA and no headers, score should be very high
        self::assertTrue($detector->analyze($noUa)->score >= 70);
    }

    #[Test]
    public function scoreClampedTo100(): void
    {
        // Request designed to trigger maximum signals
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'Accept' => '*/*',
            ],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $detector = new BotDetector();
        $score = $detector->analyze($request);

        self::assertLessThanOrEqual(100, $score->score);
    }

    #[Test]
    public function shortUserAgentAddsPenalty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'User-Agent' => 'MyApp',
                'Accept' => 'text/html',
                'Accept-Language' => 'en',
                'Accept-Encoding' => 'gzip',
                'Connection' => 'keep-alive',
            ],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $detector = new BotDetector();
        $score = $detector->analyze($request);

        self::assertArrayHasKey('user_agent', $score->signals);
        self::assertGreaterThan(0, $score->signals['user_agent']);
    }
}
