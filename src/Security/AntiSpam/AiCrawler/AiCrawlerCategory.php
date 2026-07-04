<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use Pulsar\Api\Api;

/**
 * Classification of an AI crawler, used to pick a default policy action.
 *
 * The distinction matters: blocking a training-data scraper is usually
 * desirable, whereas blocking a user-triggered assistant fetch or an
 * AI-powered search indexer degrades a real user experience or discoverability.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiCrawlerCategory: string
{
    /** Bulk crawling to build AI training datasets (e.g. GPTBot, CCBot, ClaudeBot). */
    case Training = 'training';

    /** User-triggered, on-demand fetch by an AI assistant (e.g. ChatGPT-User). */
    case Assistant = 'assistant';

    /** AI-powered search indexing that drives discoverability (e.g. OAI-SearchBot, Applebot). */
    case Search = 'search';
}
