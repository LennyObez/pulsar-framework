<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\RoutingConfig;

#[CoversClass(RoutingConfig::class)]
final class RoutingConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PULSAR_ROUTING_REDIRECT_TO_CANONICAL_PATH');
    }

    #[Test]
    public function canonicalRedirectIsOffByDefaultForBackwardsCompatibility(): void
    {
        self::assertFalse(new RoutingConfig()->redirectToCanonicalPath);
        self::assertFalse(RoutingConfig::fromArray([], Environment::load())->redirectToCanonicalPath);
    }

    #[Test]
    public function theFlagIsReadFromTheConfigArray(): void
    {
        $config = RoutingConfig::fromArray(['redirect_to_canonical_path' => true], Environment::load());

        self::assertTrue($config->redirectToCanonicalPath);
    }

    #[Test]
    public function theEnvironmentVariableOverridesTheConfigArray(): void
    {
        putenv('PULSAR_ROUTING_REDIRECT_TO_CANONICAL_PATH=true');
        self::assertTrue(RoutingConfig::fromArray(['redirect_to_canonical_path' => false], Environment::load())->redirectToCanonicalPath);

        putenv('PULSAR_ROUTING_REDIRECT_TO_CANONICAL_PATH=false');
        self::assertFalse(RoutingConfig::fromArray(['redirect_to_canonical_path' => true], Environment::load())->redirectToCanonicalPath);
    }
}
