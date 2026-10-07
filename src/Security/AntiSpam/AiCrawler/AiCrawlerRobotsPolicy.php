<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use NoDiscard;
use Pulsar\Api\Api;

use function implode;

/**
 * Renders robots.txt directives for AI crawlers, derived from the same
 * {@see AiCrawlerConfig} that drives request-time enforcement.
 *
 * robots.txt is the *advisory* layer: it is the only control point for tokens
 * that are never sent as a request User-Agent (e.g. `Google-Extended`,
 * `Applebot-Extended`), and a polite first signal for the rest. A crawler whose
 * resolved action is {@see AiCrawlerAction::Block} gets a `Disallow: /` block;
 * `Allow` and `RateLimit` crawlers are governed at the middleware (which can
 * express limits robots.txt cannot) and are left unrestricted here. Keeping both
 * layers off one config avoids the advisory and enforced policies drifting apart.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiCrawlerRobotsPolicy
{
    public function __construct(
        private AiCrawlerConfig $config,
    ) {}

    /**
     * The robots.txt block for blocked AI crawlers, or '' when the feature is
     * disabled or no crawler is blocked. Built-in crawlers are emitted in
     * declaration order, followed by operator-defined custom crawlers.
     */
    #[NoDiscard]
    public function directives(): string
    {
        if (!$this->config->enabled) {
            return '';
        }

        $blocks = [];

        foreach (AiCrawler::cases() as $crawler) {
            if ($this->config->resolveAction($crawler->value, $crawler->category()) === AiCrawlerAction::Block) {
                $blocks[] = "User-agent: {$crawler->value}\nDisallow: /";
            }
        }

        foreach ($this->config->customCrawlers as $token => $category) {
            if ($this->config->resolveAction($token, $category) === AiCrawlerAction::Block) {
                $blocks[] = "User-agent: {$token}\nDisallow: /";
            }
        }

        if ($blocks === []) {
            return '';
        }

        return "# AI crawlers (managed by Pulsar AntiSpam)\n" . implode("\n\n", $blocks);
    }
}
