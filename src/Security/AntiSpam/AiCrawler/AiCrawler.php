<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use Pulsar\Api\Api;

/**
 * Well-known AI crawlers, keyed by the exact (case-insensitive) substring matched
 * in the request User-Agent header.
 *
 * Coverage reflects the major AI training crawlers, on-demand assistant fetchers,
 * and AI-search indexers active as of 2025–2026. Two values — Google-Extended and
 * Applebot-Extended — are robots.txt-only opt-out tokens, never sent as a request
 * User-Agent; they are surfaced here for robots.txt generation and excluded from
 * request-time detection ({@see isRequestCrawler()}). Operators can register
 * additional crawlers via AiCrawlerConfig::$customCrawlers without a code change.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiCrawler: string
{
    // --- Training-data crawlers ---
    case GptBot = 'GPTBot';
    case ClaudeBot = 'ClaudeBot';
    case AnthropicAi = 'anthropic-ai';
    case CcBot = 'CCBot';
    case Bytespider = 'Bytespider';
    case Amazonbot = 'Amazonbot';
    case MetaExternalAgent = 'meta-externalagent';
    case PerplexityBot = 'PerplexityBot';
    case CohereAi = 'cohere-ai';
    case Diffbot = 'Diffbot';
    case ImagesiftBot = 'ImagesiftBot';
    case Omgili = 'omgili';
    case YouBot = 'YouBot';
    case Ai2Bot = 'AI2Bot';
    case TimpiBot = 'Timpibot';

    // --- On-demand assistant fetchers (user-triggered) ---
    case ChatGptUser = 'ChatGPT-User';
    case ClaudeUser = 'Claude-User';
    case ClaudeWeb = 'Claude-Web';
    case PerplexityUser = 'Perplexity-User';
    case MetaExternalFetcher = 'meta-externalfetcher';

    // --- AI-powered search indexers ---
    case OaiSearchBot = 'OAI-SearchBot';
    case Applebot = 'Applebot';

    // --- robots.txt-only opt-out tokens (not request User-Agents) ---
    case GoogleExtended = 'Google-Extended';
    case ApplebotExtended = 'Applebot-Extended';

    public function category(): AiCrawlerCategory
    {
        return match ($this) {
            self::ChatGptUser, self::ClaudeUser, self::ClaudeWeb,
            self::PerplexityUser, self::MetaExternalFetcher => AiCrawlerCategory::Assistant,
            self::OaiSearchBot, self::Applebot => AiCrawlerCategory::Search,
            default => AiCrawlerCategory::Training,
        };
    }

    /**
     * Whether this token is only meaningful in robots.txt (an AI-use opt-out
     * directive), never sent as a request User-Agent.
     */
    public function isRobotsTxtOnly(): bool
    {
        return $this === self::GoogleExtended || $this === self::ApplebotExtended;
    }

    /**
     * Whether this crawler can be detected from a request User-Agent.
     */
    public function isRequestCrawler(): bool
    {
        return !$this->isRobotsTxtOnly();
    }
}
