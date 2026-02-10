<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Parsed referrer information.
 */
#[Api(since: '1.0.0')]
final readonly class ReferrerSource
{
    public function __construct(
        public string $source,
        public string $medium = '',
        public string $campaign = '',
        public string $rawUrl = '',
    ) {}

    public static function direct(): self
    {
        return new self(source: 'Direct / None', medium: 'none');
    }

    public static function fromOrganic(string $engine, string $rawUrl = ''): self
    {
        return new self(source: $engine, medium: 'organic', rawUrl: $rawUrl);
    }

    public static function fromSocial(string $network, string $rawUrl = ''): self
    {
        return new self(source: $network, medium: 'social', rawUrl: $rawUrl);
    }

    public static function fromReferral(string $domain, string $rawUrl = ''): self
    {
        return new self(source: $domain, medium: 'referral', rawUrl: $rawUrl);
    }

    public static function fromUtm(string $source, string $medium, string $campaign, string $rawUrl = ''): self
    {
        return new self(source: $source, medium: $medium, campaign: $campaign, rawUrl: $rawUrl);
    }

    public function isDirect(): bool
    {
        return $this->medium === 'none';
    }
}
