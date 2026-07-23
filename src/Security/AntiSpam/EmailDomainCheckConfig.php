<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_values;
use function is_array;
use function is_string;

/**
 * Configuration for the native e-mail-domain anti-spam check.
 *
 * Two independent signals — a disposable/throwaway-domain list and MX
 * deliverability — each hard-gate, score, or are off, so a deployment that
 * must never lose a lead keeps both on 'score' while a stricter one uses
 * 'hard'. See {@see EmailDomainCheck} and config/anti-spam.php.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class EmailDomainCheckConfig
{
    /**
     * @param bool $enabled Master switch for the check
     * @param EmailDomainSignalMode $disposableBlock How a disposable-domain hit participates
     * @param string|null $disposableListPath Path to a newline-delimited file whose domains
     *        EXTEND the bundled list (null = bundled list only)
     * @param list<string> $disposableListInline Inline domains that EXTEND the bundled list
     * @param bool $mxCheckEnabled Evaluate MX/A deliverability at all
     * @param EmailDomainSignalMode $mxBlock How an undeliverable-domain result participates
     * @param bool $mxFailOpen Treat an unreachable resolver as deliverable (never block everyone)
     * @param int $mxCacheTtlSeconds TTL for cached positive/negative MX results
     */
    public function __construct(
        public bool $enabled = true,
        public EmailDomainSignalMode $disposableBlock = EmailDomainSignalMode::Hard,
        public ?string $disposableListPath = null,
        public array $disposableListInline = [],
        public bool $mxCheckEnabled = true,
        public EmailDomainSignalMode $mxBlock = EmailDomainSignalMode::Hard,
        public bool $mxFailOpen = true,
        public int $mxCacheTtlSeconds = 86400,
    ) {}

    /**
     * True when at least one signal would actually run, so the wiring can skip
     * registering an inert check.
     */
    #[NoDiscard]
    public function hasActiveSignal(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->disposableBlock !== EmailDomainSignalMode::Off
            || ($this->mxCheckEnabled && $this->mxBlock !== EmailDomainSignalMode::Off);
    }

    /**
     * @param array<string, mixed> $data The raw config/anti-spam.php array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $disposableList */
        $disposableList = $data['disposable_list'] ?? null;

        $path = is_string($disposableList) ? $disposableList : null;
        $inline = is_array($disposableList)
            ? array_values(array_filter($disposableList, 'is_string'))
            : [];

        return new self(
            enabled: Coerce::strictBool($data['email_domain_check_enabled'] ?? null, true),
            disposableBlock: EmailDomainSignalMode::fromValue(
                $data['disposable_block'] ?? null,
                EmailDomainSignalMode::Hard,
            ),
            disposableListPath: $path,
            disposableListInline: $inline,
            mxCheckEnabled: Coerce::strictBool($data['mx_check_enabled'] ?? null, true),
            mxBlock: EmailDomainSignalMode::fromValue(
                $data['mx_block'] ?? null,
                EmailDomainSignalMode::Hard,
            ),
            mxFailOpen: Coerce::strictBool($data['mx_fail_open'] ?? null, true),
            mxCacheTtlSeconds: Coerce::int($data['mx_cache_ttl'] ?? null, 86400),
        );
    }
}
