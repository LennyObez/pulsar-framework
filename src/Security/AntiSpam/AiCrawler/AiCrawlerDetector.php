<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;

use function stripos;

/**
 * Detects a known (or operator-configured) AI crawler from a request's
 * User-Agent header.
 *
 * Matching is a case-insensitive substring test against the built-in
 * {@see AiCrawler} tokens (robots.txt-only tokens excluded) and any custom
 * crawlers registered in {@see AiCrawlerConfig::$customCrawlers}. The built-in
 * list is checked first so its precise categories take precedence.
 */
#[Internal]
final readonly class AiCrawlerDetector
{
    public function __construct(
        private AiCrawlerConfig $config,
    ) {}

    #[NoDiscard]
    public function detect(ServerRequestInterface $request): ?DetectedAiCrawler
    {
        $userAgent = $request->getHeaderLine('User-Agent');

        if ($userAgent === '') {
            return null;
        }

        foreach (AiCrawler::cases() as $crawler) {
            if ($crawler->isRequestCrawler() && stripos($userAgent, $crawler->value) !== false) {
                return new DetectedAiCrawler($crawler->value, $crawler->category());
            }
        }

        foreach ($this->config->customCrawlers as $token => $category) {
            if (stripos($userAgent, $token) !== false) {
                return new DetectedAiCrawler($token, $category);
            }
        }

        return null;
    }
}
