<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\AiCrawler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerVerificationConfig;

#[CoversClass(AiCrawlerVerificationConfig::class)]
final class AiCrawlerVerificationConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new AiCrawlerVerificationConfig();

        self::assertFalse($config->enabled);
        self::assertFalse($config->reverseDns);
        self::assertSame([], $config->ranges);
        self::assertSame([], $config->domains);
        self::assertSame(3600, $config->cacheTtlSeconds);
    }

    #[Test]
    public function fromArrayParsesEveryField(): void
    {
        $config = AiCrawlerVerificationConfig::fromArray([
            'enabled' => true,
            'reverse_dns' => true,
            'ranges' => ['GPTBot' => ['203.0.113.0/24', '198.51.100.0/24']],
            'domains' => ['GPTBot' => ['openai.com']],
            'cache_ttl_seconds' => 7200,
        ]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->reverseDns);
        self::assertSame(['203.0.113.0/24', '198.51.100.0/24'], $config->rangesFor('GPTBot'));
        self::assertSame(['openai.com'], $config->domainsFor('GPTBot'));
        self::assertSame(7200, $config->cacheTtlSeconds);
    }

    #[Test]
    public function unknownCrawlerYieldsEmptyRangesAndDomains(): void
    {
        $config = AiCrawlerVerificationConfig::fromArray(['enabled' => true, 'ranges' => ['GPTBot' => ['10.0.0.0/8']]]);

        self::assertSame([], $config->rangesFor('CCBot'));
        self::assertSame([], $config->domainsFor('CCBot'));
    }

    #[Test]
    public function normalizesMapsFilteringNonStringEntries(): void
    {
        /** @psalm-suppress InvalidArgument intentionally malformed config */
        $config = AiCrawlerVerificationConfig::fromArray([
            'ranges' => [
                'GPTBot' => ['10.0.0.0/8', 42, null, '203.0.113.0/24'],
                7 => ['ignored-non-string-key'],
                'CCBot' => 'not-a-list',
            ],
        ]);

        self::assertSame(['10.0.0.0/8', '203.0.113.0/24'], $config->rangesFor('GPTBot'));
        self::assertSame([], $config->rangesFor('CCBot'), 'non-array value dropped');
    }
}
