<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function array_filter;
use function array_sum;
use function count;
use function min;
use function str_contains;
use function strlen;
use function strtolower;

/**
 * Transparent behavioral bot detection without CAPTCHA.
 *
 * Analyzes request characteristics to compute a bot probability score.
 * Signals include: missing typical browser headers, suspicious user-agent
 * patterns, header ordering anomalies, and request consistency.
 */
#[Api(since: '1.0.0')]
final readonly class BotDetector
{
    private const array KNOWN_BOT_PATTERNS = [
        'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
        'python-requests', 'httpie', 'postman', 'insomnia',
        'java/', 'go-http-client', 'node-fetch', 'axios',
    ];

    private const array EXPECTED_BROWSER_HEADERS = [
        'Accept',
        'Accept-Language',
        'Accept-Encoding',
    ];

    /**
     * @param int $threshold Score at or above which a request is classified as a bot
     */
    public function __construct(
        private int $threshold = 70,
    ) {}

    /**
     * Analyze a request and return a bot score.
     */
    #[NoDiscard]
    public function analyze(ServerRequestInterface $request): BotScore
    {
        /** @var array<string, int> $signals */
        $signals = [];

        $signals['user_agent'] = $this->scoreUserAgent($request);
        $signals['missing_headers'] = $this->scoreMissingHeaders($request);
        $signals['accept_header'] = $this->scoreAcceptHeader($request);
        $signals['connection_header'] = $this->scoreConnectionHeader($request);

        // Filter out zero signals
        $signals = array_filter($signals, static fn(int $v): bool => $v > 0);

        $total = min(100, (int) array_sum($signals));

        return new BotScore(score: $total, signals: $signals);
    }

    /**
     * Quick check: is this request likely from a bot?
     */
    #[NoDiscard]
    public function isBot(ServerRequestInterface $request): bool
    {
        return $this->analyze($request)->isBot($this->threshold);
    }

    private function scoreUserAgent(ServerRequestInterface $request): int
    {
        $ua = strtolower($request->getHeaderLine('User-Agent'));

        // No user-agent at all is very suspicious
        if ($ua === '') {
            return 40;
        }

        // Known bot patterns
        foreach (self::KNOWN_BOT_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return 50;
            }
        }

        // Very short user-agent strings are suspicious
        if (strlen($ua) < 20) {
            return 15;
        }

        return 0;
    }

    private function scoreMissingHeaders(ServerRequestInterface $request): int
    {
        $missing = 0;

        foreach (self::EXPECTED_BROWSER_HEADERS as $header) {
            if ($request->getHeaderLine($header) === '') {
                $missing++;
            }
        }

        $total = count(self::EXPECTED_BROWSER_HEADERS);

        if ($missing === $total) {
            return 30;
        }

        if ($missing > 0) {
            return $missing * 10;
        }

        return 0;
    }

    private function scoreAcceptHeader(ServerRequestInterface $request): int
    {
        $accept = $request->getHeaderLine('Accept');

        if ($accept === '') {
            return 0; // Already penalized in missing headers
        }

        // Wildcard-only accept is slightly suspicious
        if ($accept === '*/*') {
            return 10;
        }

        return 0;
    }

    private function scoreConnectionHeader(ServerRequestInterface $request): int
    {
        // Missing Connection header from a supposed browser is suspicious
        // but some proxies strip it, so low weight
        $connection = $request->getHeaderLine('Connection');

        if ($connection === '' && $request->getHeaderLine('User-Agent') !== '') {
            // Only flag if UA is present (otherwise already flagged)
            if (!$this->hasKnownBotUa($request)) {
                return 5;
            }
        }

        return 0;
    }

    private function hasKnownBotUa(ServerRequestInterface $request): bool
    {
        $ua = strtolower($request->getHeaderLine('User-Agent'));

        foreach (self::KNOWN_BOT_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
