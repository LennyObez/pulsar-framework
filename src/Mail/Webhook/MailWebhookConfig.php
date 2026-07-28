<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;
use Pulsar\Support\Coerce;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * Configuration for the inbound mail-provider webhook endpoint.
 *
 * Opt-in (disabled by default): an always-on webhook route is an attack
 * surface, so the secure handler stack and its route are only wired when an
 * operator configures a provider and its signing secret. When enabled, Pulsar
 * exposes a single POST endpoint that verifies the provider signature, rejects
 * replays, deduplicates events, and audit-logs bounces and complaints.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MailWebhookConfig implements ReportsUnknownKeys
{
    /** Keys read from the `webhooks` sub-array of config/mail.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'provider', 'secret', 'path', 'replay_window_seconds', 'ip_allowlist',
    ];

    /**
     * @param list<string> $ipAllowlist Source IPs/CIDRs permitted to call the endpoint (empty = no IP restriction)
     * @param list<string> $unknownKeys Keys present in the raw `webhooks` array that this
     *     DTO does not read. Two of these are the endpoint's only defences: a misspelled
     *     `secret` leaves signature verification without a key, and a misspelled
     *     `ip_allowlist` drops the source restriction — on a route that accepts
     *     unauthenticated provider callbacks.
     */
    public function __construct(
        public bool $enabled = false,
        public string $provider = '',
        public string $secret = '',
        public string $path = '/_pulsar/mail/webhook',
        public int $replayWindowSeconds = 300,
        public array $ipAllowlist = [],
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * SES uses certificate-based verification rather than a shared secret, so a
     * configured SES provider is usable without a secret; every other provider
     * needs one.
     */
    #[NoDiscard]
    public function isUsable(): bool
    {
        if (!$this->enabled || $this->provider === '' || $this->path === '') {
            return false;
        }

        return $this->provider === 'ses' || $this->secret !== '';
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     provider?: string,
     *     secret?: string,
     *     path?: string,
     *     replay_window_seconds?: int|string,
     *     ip_allowlist?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $ipAllowlist = $data['ip_allowlist'] ?? null;

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            provider: Coerce::string($data['provider'] ?? null, ''),
            secret: Coerce::string($data['secret'] ?? null, ''),
            path: Coerce::string($data['path'] ?? null, '/_pulsar/mail/webhook'),
            replayWindowSeconds: Coerce::int($data['replay_window_seconds'] ?? null, 300),
            ipAllowlist: is_array($ipAllowlist) ? array_values(array_filter($ipAllowlist, is_string(...))) : [],
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
