<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DomainConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\TrustedProxy;
use Pulsar\Routing\DomainContext;
use Pulsar\Routing\Internal\ConfigDomainResolver;

#[CoversClass(ConfigDomainResolver::class)]
#[CoversClass(DomainContext::class)]
final class ConfigDomainResolverTest extends TestCase
{
    private function makeRequest(
        string $host,
        ?string $forwardedHost = null,
        string $remoteAddr = '',
    ): ServerRequest {
        $uri = new Uri(scheme: 'http', host: $host, path: '/');

        $headers = [];

        if ($forwardedHost !== null) {
            $headers['X-Forwarded-Host'] = [$forwardedHost];
        }

        $serverParams = $remoteAddr !== '' ? ['REMOTE_ADDR' => $remoteAddr] : [];

        return new ServerRequest('GET', $uri, $headers, serverParams: $serverParams);
    }

    #[Test]
    public function defaultDomainWithNoSubdomainMappings(): void
    {
        $config = new DomainConfig(defaultDomain: 'example.com');
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('example.com'));

        self::assertSame('example.com', $ctx->domain);
        self::assertSame('', $ctx->subdomain);
        self::assertSame([], $ctx->extensionScopes);
        self::assertTrue($ctx->isDefault);
    }

    #[Test]
    public function exactDefaultDomainMatchWithSubdomainMappingsConfigured(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('example.com'));

        self::assertTrue($ctx->isDefault);
        self::assertSame('', $ctx->subdomain);
    }

    #[Test]
    public function subdomainResolvesToConfiguredScopes(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: [
                'forum' => ['forum'],
                'api' => ['api', 'graphql'],
            ],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('forum.example.com'));

        self::assertSame('forum.example.com', $ctx->domain);
        self::assertSame('forum', $ctx->subdomain);
        self::assertSame(['forum'], $ctx->extensionScopes);
        self::assertFalse($ctx->isDefault);
    }

    #[Test]
    public function multiScopeSubdomain(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['api' => ['api', 'graphql']],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('api.example.com'));

        self::assertSame(['api', 'graphql'], $ctx->extensionScopes);
        self::assertSame('api', $ctx->subdomain);
    }

    #[Test]
    public function unmappedSubdomainTreatedAsDefault(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('unknown.example.com'));

        self::assertTrue($ctx->isDefault);
        self::assertSame('', $ctx->subdomain);
    }

    #[Test]
    public function completelyDifferentDomainTreatedAsDefault(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('other-site.com'));

        self::assertTrue($ctx->isDefault);
    }

    #[Test]
    public function hostPortIsStripped(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('forum.example.com:8080'));

        self::assertSame('forum.example.com', $ctx->domain);
        self::assertSame('forum', $ctx->subdomain);
        self::assertFalse($ctx->isDefault);
    }

    #[Test]
    public function caseInsensitiveHostMatching(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'Example.COM',
            subdomains: ['Forum' => ['forum']],
        );
        $resolver = new ConfigDomainResolver($config);

        // Note: subdomain key lookup is case-sensitive in the config,
        // but host comparison is case-insensitive. The subdomain extracted
        // is lowercased from the host, so it matches the config key.
        $ctx = $resolver->resolve($this->makeRequest('FORUM.EXAMPLE.COM'));

        // The host is lowercased for comparison, but the subdomain key
        // in the config is 'Forum' (case-sensitive map lookup). Since
        // PHP 'forum' !== 'Forum', this will be treated as default.
        // This is correct behavior: config keys should be lowercase.
        self::assertTrue($ctx->isDefault);
    }

    #[Test]
    public function caseInsensitiveWithLowercaseConfigKeys(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('FORUM.EXAMPLE.COM'));

        self::assertSame('forum', $ctx->subdomain);
        self::assertFalse($ctx->isDefault);
    }

    #[Test]
    public function xForwardedHostTakesPrecedence(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );
        // X-Forwarded-Host is only honoured from a trusted proxy (anti host-header
        // injection); 127.0.0.1 is trusted by TrustedProxy's defaults.
        $resolver = new ConfigDomainResolver($config, new TrustedProxy());

        $ctx = $resolver->resolve(
            $this->makeRequest('internal-lb.local', 'forum.example.com', '127.0.0.1'),
        );

        self::assertSame('forum.example.com', $ctx->domain);
        self::assertSame('forum', $ctx->subdomain);
        self::assertFalse($ctx->isDefault);
    }

    #[Test]
    public function xForwardedHostMultipleValuesUsesFirst(): void
    {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );
        $resolver = new ConfigDomainResolver($config, new TrustedProxy());

        $ctx = $resolver->resolve(
            $this->makeRequest('backend', 'forum.example.com, proxy.internal', '127.0.0.1'),
        );

        self::assertSame('forum.example.com', $ctx->domain);
        self::assertSame('forum', $ctx->subdomain);
    }

    #[Test]
    public function localhostDefaultWithNoMappings(): void
    {
        $config = new DomainConfig();
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest('localhost'));

        self::assertTrue($ctx->isDefault);
        self::assertSame('localhost', $ctx->domain);
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function subdomainExtractionProvider(): iterable
    {
        yield 'simple subdomain' => ['forum.example.com', 'forum', 'forum.example.com', false];
        yield 'default domain' => ['example.com', '', 'example.com', true];
        yield 'admin subdomain' => ['admin.example.com', 'admin', 'admin.example.com', false];
    }

    #[Test]
    #[DataProvider('subdomainExtractionProvider')]
    public function subdomainExtraction(
        string $host,
        string $expectedSubdomain,
        string $expectedDomain,
        bool $expectedDefault,
    ): void {
        $config = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: [
                'forum' => ['forum'],
                'admin' => ['cms-admin'],
            ],
        );
        $resolver = new ConfigDomainResolver($config);

        $ctx = $resolver->resolve($this->makeRequest($host));

        self::assertSame($expectedSubdomain, $ctx->subdomain);
        self::assertSame($expectedDomain, $ctx->domain);
        self::assertSame($expectedDefault, $ctx->isDefault);
    }
}
