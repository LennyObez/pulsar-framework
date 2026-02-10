<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Introspection\IntrospectionConfig;
use ReflectionClass;

#[CoversClass(IntrospectionConfig::class)]
final class IntrospectionConfigTest extends TestCase
{
    /**
     * Create an Environment instance with the given variables using Reflection.
     *
     * @param array<string, string> $variables
     */
    private static function makeEnvironment(array $variables): Environment
    {
        $reflection = new ReflectionClass(Environment::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('variables');
        $prop->setValue($instance, $variables);

        return $instance;
    }

    #[Test]
    public function fromArrayDefaultsToEnabledInLocal(): void
    {
        $env = self::makeEnvironment([]);

        $config = IntrospectionConfig::fromArray([], $env, EnvironmentMode::Local);
        self::assertTrue($config->enabled);

        $config = IntrospectionConfig::fromArray([], $env, EnvironmentMode::Staging);
        self::assertTrue($config->enabled);

        $config = IntrospectionConfig::fromArray([], $env, EnvironmentMode::Production);
        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayEnvOverridesConfig(): void
    {
        $envTrue = self::makeEnvironment(['INTROSPECTION_ENABLED' => 'true']);

        $config = IntrospectionConfig::fromArray(['enabled' => false], $envTrue, EnvironmentMode::Production);
        self::assertTrue($config->enabled);

        $envFalse = self::makeEnvironment(['INTROSPECTION_ENABLED' => 'false']);

        $config = IntrospectionConfig::fromArray(['enabled' => true], $envFalse, EnvironmentMode::Local);
        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayFallsBackToConfigValue(): void
    {
        $env = self::makeEnvironment([]);

        $config = IntrospectionConfig::fromArray(['enabled' => true], $env, EnvironmentMode::Production);
        self::assertTrue($config->enabled);

        $config = IntrospectionConfig::fromArray(['enabled' => false], $env, EnvironmentMode::Local);
        self::assertFalse($config->enabled);
    }
}
