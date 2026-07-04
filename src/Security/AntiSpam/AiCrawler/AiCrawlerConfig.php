<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;
use function is_string;

/**
 * Configuration for the AI-crawler defense.
 *
 * Off by default. When enabled, detected AI crawlers are actioned by category
 * (training/assistant/search) with optional per-crawler overrides, and a
 * TDM-reservation header (X-Robots-Tag: noai, noimageai) is added so that even
 * allowed crawlers are told the content is not licensed for AI use.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiCrawlerConfig
{
    /**
     * @param array<string, AiCrawlerAction> $overrides Per-crawler UA-token => action, highest precedence
     * @param array<string, AiCrawlerCategory> $customCrawlers Extra UA-token => category not in the built-in list
     */
    public function __construct(
        public bool $enabled = false,
        public AiCrawlerAction $trainingAction = AiCrawlerAction::Block,
        public AiCrawlerAction $assistantAction = AiCrawlerAction::Allow,
        public AiCrawlerAction $searchAction = AiCrawlerAction::Allow,
        public array $overrides = [],
        public array $customCrawlers = [],
        public bool $sendTdmReservation = true,
        public int $rateLimitMaxRequests = 60,
        public int $rateLimitWindowSeconds = 60,
    ) {}

    /**
     * Resolve the action for a detected crawler: a per-crawler override wins,
     * otherwise the category default applies.
     */
    #[NoDiscard]
    public function resolveAction(string $token, AiCrawlerCategory $category): AiCrawlerAction
    {
        return $this->overrides[$token] ?? match ($category) {
            AiCrawlerCategory::Training => $this->trainingAction,
            AiCrawlerCategory::Assistant => $this->assistantAction,
            AiCrawlerCategory::Search => $this->searchAction,
        };
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     training_action?: string,
     *     assistant_action?: string,
     *     search_action?: string,
     *     overrides?: array<string, string>,
     *     custom_crawlers?: array<string, string>,
     *     send_tdm_reservation?: bool|int|string,
     *     rate_limit_max_requests?: int,
     *     rate_limit_window_seconds?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $overridesRaw = $data['overrides'] ?? null;
        $customRaw = $data['custom_crawlers'] ?? null;

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            trainingAction: AiCrawlerAction::fromString(Coerce::string($data['training_action'] ?? null, 'block'), AiCrawlerAction::Block),
            assistantAction: AiCrawlerAction::fromString(Coerce::string($data['assistant_action'] ?? null, 'allow'), AiCrawlerAction::Allow),
            searchAction: AiCrawlerAction::fromString(Coerce::string($data['search_action'] ?? null, 'allow'), AiCrawlerAction::Allow),
            overrides: is_array($overridesRaw) ? self::parseActionMap($overridesRaw) : [],
            customCrawlers: is_array($customRaw) ? self::parseCategoryMap($customRaw) : [],
            sendTdmReservation: Coerce::strictBool($data['send_tdm_reservation'] ?? null, true),
            rateLimitMaxRequests: Coerce::int($data['rate_limit_max_requests'] ?? null, 60),
            rateLimitWindowSeconds: Coerce::int($data['rate_limit_window_seconds'] ?? null, 60),
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, AiCrawlerAction>
     */
    private static function parseActionMap(array $raw): array
    {
        $map = [];

        /** @psalm-suppress MixedAssignment Raw config map values are inherently mixed; each is validated by is_string before use. */
        foreach ($raw as $token => $action) {
            if (is_string($token) && is_string($action)) {
                $resolved = AiCrawlerAction::tryFrom($action);

                if ($resolved !== null) {
                    $map[$token] = $resolved;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, AiCrawlerCategory>
     */
    private static function parseCategoryMap(array $raw): array
    {
        $map = [];

        /** @psalm-suppress MixedAssignment Raw config map values are inherently mixed; each is validated by is_string before use. */
        foreach ($raw as $token => $category) {
            if (is_string($token) && is_string($category)) {
                $resolved = AiCrawlerCategory::tryFrom($category);

                if ($resolved !== null) {
                    $map[$token] = $resolved;
                }
            }
        }

        return $map;
    }
}
