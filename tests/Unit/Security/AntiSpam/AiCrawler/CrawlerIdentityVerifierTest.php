<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\AiCrawler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerVerificationConfig;
use Pulsar\Security\AntiSpam\AiCrawler\CrawlerIdentity;
use Pulsar\Security\AntiSpam\AiCrawler\Internal\CrawlerDnsResolver;
use Pulsar\Security\AntiSpam\AiCrawler\Internal\CrawlerIdentityVerifier;

#[CoversClass(CrawlerIdentityVerifier::class)]
final class CrawlerIdentityVerifierTest extends TestCase
{
    #[Test]
    public function unverifiableWhenDisabled(): void
    {
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(enabled: false, ranges: ['GPTBot' => ['203.0.113.0/24']]),
            $this->dns([], []),
        );

        self::assertSame(CrawlerIdentity::Unverifiable, $verifier->verify('GPTBot', '203.0.113.5'));
    }

    #[Test]
    public function unverifiableWhenNoDataForCrawler(): void
    {
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(enabled: true, ranges: ['GPTBot' => ['203.0.113.0/24']]),
            $this->dns([], []),
        );

        self::assertSame(CrawlerIdentity::Unverifiable, $verifier->verify('CCBot', '203.0.113.5'));
    }

    #[Test]
    public function verifiedWhenIpInPublishedRange(): void
    {
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(enabled: true, ranges: ['GPTBot' => ['203.0.113.0/24']]),
            $this->dns([], []),
        );

        self::assertSame(CrawlerIdentity::Verified, $verifier->verify('GPTBot', '203.0.113.5'));
    }

    #[Test]
    public function impersonatorWhenIpOutsidePublishedRange(): void
    {
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(enabled: true, ranges: ['GPTBot' => ['203.0.113.0/24']]),
            $this->dns([], []),
        );

        self::assertSame(CrawlerIdentity::Impersonator, $verifier->verify('GPTBot', '8.8.8.8'));
    }

    #[Test]
    public function verifiedViaForwardConfirmedReverseDns(): void
    {
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(
                enabled: true,
                reverseDns: true,
                domains: ['GPTBot' => ['openai.com']],
            ),
            $this->dns(
                reverse: ['203.0.113.5' => ['crawl-203-0-113-5.openai.com']],
                forward: ['crawl-203-0-113-5.openai.com' => ['203.0.113.5']],
            ),
        );

        self::assertSame(CrawlerIdentity::Verified, $verifier->verify('GPTBot', '203.0.113.5'));
    }

    #[Test]
    public function impersonatorWhenReverseDnsHostSuffixDoesNotMatch(): void
    {
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(enabled: true, reverseDns: true, domains: ['GPTBot' => ['openai.com']]),
            $this->dns(
                reverse: ['203.0.113.5' => ['evil.attacker.com']],
                forward: ['evil.attacker.com' => ['203.0.113.5']],
            ),
        );

        self::assertSame(CrawlerIdentity::Impersonator, $verifier->verify('GPTBot', '203.0.113.5'));
    }

    #[Test]
    public function impersonatorWhenForwardDoesNotConfirm(): void
    {
        // PTR host suffix matches, but it forward-resolves to a different IP
        // (a classic spoof: attacker controls reverse DNS but not the domain).
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(enabled: true, reverseDns: true, domains: ['GPTBot' => ['openai.com']]),
            $this->dns(
                reverse: ['8.8.8.8' => ['spoof.openai.com']],
                forward: ['spoof.openai.com' => ['203.0.113.5']],
            ),
        );

        self::assertSame(CrawlerIdentity::Impersonator, $verifier->verify('GPTBot', '8.8.8.8'));
    }

    #[Test]
    public function reverseDnsNotConsultedWhenDisabledEvenWithDomains(): void
    {
        // domains configured but reverse_dns off and no ranges => unverifiable.
        $verifier = new CrawlerIdentityVerifier(
            new AiCrawlerVerificationConfig(enabled: true, reverseDns: false, domains: ['GPTBot' => ['openai.com']]),
            $this->dns(reverse: ['203.0.113.5' => ['x.openai.com']], forward: ['x.openai.com' => ['203.0.113.5']]),
        );

        self::assertSame(CrawlerIdentity::Unverifiable, $verifier->verify('GPTBot', '203.0.113.5'));
    }

    /**
     * @param array<string, list<string>> $reverse ip => host names
     * @param array<string, list<string>> $forward host => ip addresses
     */
    private function dns(array $reverse, array $forward): CrawlerDnsResolver
    {
        return new class ($reverse, $forward) implements CrawlerDnsResolver {
            /**
             * @param array<string, list<string>> $reverse
             * @param array<string, list<string>> $forward
             */
            public function __construct(
                private readonly array $reverse,
                private readonly array $forward,
            ) {}

            public function reverse(string $ip): array
            {
                return $this->reverse[$ip] ?? [];
            }

            public function forward(string $host): array
            {
                return $this->forward[$host] ?? [];
            }
        };
    }
}
