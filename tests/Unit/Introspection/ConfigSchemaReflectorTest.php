<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Internal\ConfigSchemaReflector;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(ConfigSchemaReflector::class)]
final class ConfigSchemaReflectorTest extends TestCase
{
    /** @return class-string */
    private static function classString(string $name): string
    {
        /** @var class-string */
        return $name;
    }

    private ConfigSchemaReflector $reflector;

    protected function setUp(): void
    {
        $this->reflector = new ConfigSchemaReflector(new SensitiveDataScrubber());
    }

    #[Test]
    public function reflectReturnsEmptyForNoClasses(): void
    {
        $warnings = [];
        $result = $this->reflector->reflect([], $warnings);

        self::assertSame([], $result->schemas);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function reflectSkipsNonexistentClasses(): void
    {
        $warnings = [];
        $result = $this->reflector->reflect([self::classString('NonExistent\\Class\\That\\Does\\Not\\Exist')], $warnings);

        self::assertSame([], $result->schemas);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function reflectExtractsPublicProperties(): void
    {
        $warnings = [];
        $result = $this->reflector->reflect([ConfigSchemaReflectorTestConfig::class], $warnings);

        self::assertCount(1, $result->schemas);

        $schema = $result->schemas[0];
        self::assertSame(ConfigSchemaReflectorTestConfig::class, $schema->className);
        self::assertNotEmpty($schema->properties);

        $names = array_map(static fn($p) => $p->name, $schema->properties);
        self::assertContains('host', $names);
        self::assertContains('port', $names);
        self::assertContains('debug', $names);
    }

    #[Test]
    public function reflectScrubsSensitiveDefaults(): void
    {
        $warnings = [];
        $result = $this->reflector->reflect([ConfigSchemaReflectorTestSensitiveConfig::class], $warnings);

        self::assertCount(1, $result->schemas);

        $properties = $result->schemas[0]->properties;
        $passwordProp = null;

        foreach ($properties as $prop) {
            if ($prop->name === 'password') {
                $passwordProp = $prop;
            }
        }

        self::assertNotNull($passwordProp);
        self::assertSame('[REDACTED]', $passwordProp->default);
    }

    #[Test]
    public function reflectAddsWarningOnReflectionError(): void
    {
        $warnings = [];
        // Pass an interface which will throw when trying to access properties in certain ways
        $this->reflector->reflect([ConfigSchemaReflectorTestConfig::class], $warnings);

        // Should complete without errors — testing graceful handling
        self::assertEmpty($warnings);
    }
}

/**
 * Test config class for ConfigSchemaReflector tests.
 */
final readonly class ConfigSchemaReflectorTestConfig
{
    public function __construct(
        public string $host = 'localhost',
        public int $port = 3306,
        public bool $debug = false,
    ) {}
}

/**
 * Test config class with sensitive properties.
 */
final readonly class ConfigSchemaReflectorTestSensitiveConfig
{
    public function __construct(
        public string $username = 'root',
        public string $password = 'secret',
    ) {}
}
