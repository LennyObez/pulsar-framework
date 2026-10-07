<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DomainConfig;
use Pulsar\Config\Environment;

#[CoversClass(DomainConfig::class)]
final class DomainConfigTest extends TestCase
{
    private function emptyEnv(): Environment
    {
        return Environment::load('/nonexistent/.env');
    }

    #[Test]
    public function defaultValues(): void
    {
        $config = new DomainConfig();

        self::assertSame('localhost', $config->defaultDomain);
        self::assertSame([], $config->subdomains);
        self::assertTrue($config->corsAcrossSubdomains);
        self::assertSame('', $config->sharedSessionDomain);
        self::assertSame('https', $config->scheme);
        self::assertFalse($config->hasSubdomainMappings());
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = DomainConfig::fromArray([], $this->emptyEnv());

        self::assertSame('localhost', $config->defaultDomain);
        self::assertSame([], $config->subdomains);
        self::assertTrue($config->corsAcrossSubdomains);
        self::assertSame('', $config->sharedSessionDomain);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = DomainConfig::fromArray([
            'default_domain' => 'example.com',
            'subdomains' => [
                'admin' => ['cms-admin'],
                'forum' => ['forum'],
                'api' => ['api', 'graphql'],
            ],
            'cors_across_subdomains' => false,
            'shared_session_domain' => '.example.com',
            'scheme' => 'http',
        ], $this->emptyEnv());

        self::assertSame('example.com', $config->defaultDomain);
        self::assertTrue($config->hasSubdomainMappings());
        self::assertSame(['cms-admin'], $config->subdomains['admin']);
        self::assertSame(['forum'], $config->subdomains['forum']);
        self::assertSame(['api', 'graphql'], $config->subdomains['api']);
        self::assertFalse($config->corsAcrossSubdomains);
        self::assertSame('.example.com', $config->sharedSessionDomain);
        self::assertSame('http', $config->scheme);
    }

    #[Test]
    public function fromArrayAcceptsStringScope(): void
    {
        $config = DomainConfig::fromArray([
            'subdomains' => [
                'admin' => 'cms-admin',
            ],
        ], $this->emptyEnv());

        self::assertSame(['cms-admin'], $config->subdomains['admin']);
    }

    #[Test]
    public function fromArrayIgnoresNonStringKeys(): void
    {
        $config = DomainConfig::fromArray([
            'subdomains' => [
                0 => ['invalid'],
                'valid' => ['scope'],
            ],
        ], $this->emptyEnv());

        self::assertCount(1, $config->subdomains);
        self::assertSame(['scope'], $config->subdomains['valid']);
    }

    #[Test]
    public function fromArrayFiltersNonStringScopes(): void
    {
        $config = DomainConfig::fromArray([
            'subdomains' => [
                'api' => ['valid', 42, null, 'also-valid'],
            ],
        ], $this->emptyEnv());

        self::assertSame(['valid', 'also-valid'], $config->subdomains['api']);
    }

    #[Test]
    public function fromArrayHandlesNonArraySubdomains(): void
    {
        $config = DomainConfig::fromArray([
            'subdomains' => 'invalid',
        ], $this->emptyEnv());

        self::assertSame([], $config->subdomains);
    }

    #[Test]
    public function fromArrayHandlesNonBoolCors(): void
    {
        $config = DomainConfig::fromArray([
            'cors_across_subdomains' => 'yes',
        ], $this->emptyEnv());

        self::assertTrue($config->corsAcrossSubdomains);
    }

    #[Test]
    public function fromArrayHandlesNonStringDomain(): void
    {
        $config = DomainConfig::fromArray([
            'default_domain' => 42,
        ], $this->emptyEnv());

        self::assertSame('localhost', $config->defaultDomain);
    }

    #[Test]
    public function fromArrayHandlesNonStringSessionDomain(): void
    {
        $config = DomainConfig::fromArray([
            'shared_session_domain' => false,
        ], $this->emptyEnv());

        self::assertSame('', $config->sharedSessionDomain);
    }

    #[Test]
    public function fromArrayHandlesNonStringScheme(): void
    {
        $config = DomainConfig::fromArray([
            'scheme' => 123,
        ], $this->emptyEnv());

        self::assertSame('https', $config->scheme);
    }

    #[Test]
    public function scopesForSubdomainReturnsMatchingScopes(): void
    {
        $config = new DomainConfig(
            subdomains: [
                'forum' => ['forum'],
                'api' => ['api', 'graphql'],
            ],
        );

        self::assertSame(['forum'], $config->scopesForSubdomain('forum'));
        self::assertSame(['api', 'graphql'], $config->scopesForSubdomain('api'));
    }

    #[Test]
    public function scopesForSubdomainReturnsNullForUnmapped(): void
    {
        $config = new DomainConfig(
            subdomains: ['forum' => ['forum']],
        );

        self::assertNull($config->scopesForSubdomain('admin'));
    }

    #[Test]
    public function subdomainForScopeFindsMapping(): void
    {
        $config = new DomainConfig(
            subdomains: [
                'forum' => ['forum', 'forum-api'],
                'admin' => ['cms-admin'],
            ],
        );

        self::assertSame('forum', $config->subdomainForScope('forum'));
        self::assertSame('forum', $config->subdomainForScope('forum-api'));
        self::assertSame('admin', $config->subdomainForScope('cms-admin'));
    }

    #[Test]
    public function subdomainForScopeReturnsNullWhenNotMapped(): void
    {
        $config = new DomainConfig(
            subdomains: ['forum' => ['forum']],
        );

        self::assertNull($config->subdomainForScope('unknown'));
    }

    #[Test]
    public function hasSubdomainMappingsReturnsFalseWhenEmpty(): void
    {
        $config = new DomainConfig(subdomains: []);

        self::assertFalse($config->hasSubdomainMappings());
    }

    #[Test]
    public function hasSubdomainMappingsReturnsTrueWhenPopulated(): void
    {
        $config = new DomainConfig(subdomains: ['forum' => ['forum']]);

        self::assertTrue($config->hasSubdomainMappings());
    }
}
