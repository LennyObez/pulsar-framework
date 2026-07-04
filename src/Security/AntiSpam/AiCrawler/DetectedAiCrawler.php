<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use Pulsar\Api\Internal;

/**
 * The result of detecting an AI crawler from a request User-Agent.
 */
#[Internal]
final readonly class DetectedAiCrawler
{
    public function __construct(
        public string $token,
        public AiCrawlerCategory $category,
    ) {}
}
