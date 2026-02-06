<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Runtime\RuntimeResolver;
use Pulsar\Runtime\RuntimeType;

#[CoversClass(RuntimeResolver::class)]
final class RuntimeResolverTest extends TestCase
{
    /** @var list<string> Env vars set during tests, to be cleaned up */
    private array $envVarsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->envVarsToClean as $key) {
            putenv($key);
        }

        $this->envVarsToClean = [];
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->envVarsToClean[] = $key;
    }

    private function createResolver(
        bool $frankenPhp = false,
        bool $roadRunner = false,
        bool $sockets = false,
        ?Environment $environment = null,
    ): RuntimeResolver {
        return new RuntimeResolver(
            environment: $environment ?? Environment::load(null),
            frankenPhpDetector: static fn(): bool => $frankenPhp,
            roadRunnerDetector: static fn(): bool => $roadRunner,
            socketsDetector: static fn(): bool => $sockets,
        );
    }

    #[Test]
    public function it_returns_configured_type_when_provided(): void
    {
        $resolver = $this->createResolver();

        self::assertSame(RuntimeType::RoadRunner, $resolver->resolve(RuntimeType::RoadRunner));
    }

    #[Test]
    public function it_returns_configured_type_regardless_of_availability(): void
    {
        $resolver = $this->createResolver(frankenPhp: true);

        self::assertSame(RuntimeType::Fpm, $resolver->resolve(RuntimeType::Fpm));
    }

    #[Test]
    public function it_auto_detects_frankenphp_first(): void
    {
        $resolver = $this->createResolver(
            frankenPhp: true,
            roadRunner: true,
            sockets: true,
        );

        self::assertSame(RuntimeType::FrankenPhp, $resolver->resolve());
    }

    #[Test]
    public function it_auto_detects_roadrunner_when_no_frankenphp(): void
    {
        $resolver = $this->createResolver(
            roadRunner: true,
            sockets: true,
        );

        self::assertSame(RuntimeType::RoadRunner, $resolver->resolve());
    }

    #[Test]
    public function it_auto_detects_persistent_when_sockets_available(): void
    {
        $resolver = $this->createResolver(sockets: true);

        self::assertSame(RuntimeType::Persistent, $resolver->resolve());
    }

    #[Test]
    public function it_falls_back_to_fpm(): void
    {
        $resolver = $this->createResolver();

        self::assertSame(RuntimeType::Fpm, $resolver->resolve());
    }

    #[Test]
    public function it_auto_detects_roadrunner_via_environment(): void
    {
        $this->setEnv('RR_MODE', 'http');

        $resolver = new RuntimeResolver(
            environment: Environment::load(null),
            frankenPhpDetector: static fn(): bool => false,
            socketsDetector: static fn(): bool => false,
        );

        self::assertSame(RuntimeType::RoadRunner, $resolver->resolve());
    }

    #[Test]
    public function available_returns_only_available_types(): void
    {
        $resolver = $this->createResolver(sockets: true);

        $available = $resolver->available();

        self::assertContains(RuntimeType::Persistent, $available);
        self::assertContains(RuntimeType::Fpm, $available);
        self::assertNotContains(RuntimeType::FrankenPhp, $available);
        self::assertNotContains(RuntimeType::RoadRunner, $available);
    }

    #[Test]
    public function available_returns_all_when_everything_present(): void
    {
        $resolver = $this->createResolver(
            frankenPhp: true,
            roadRunner: true,
            sockets: true,
        );

        self::assertCount(4, $resolver->available());
    }

    #[Test]
    public function available_always_includes_fpm(): void
    {
        $resolver = $this->createResolver();

        $available = $resolver->available();

        self::assertContains(RuntimeType::Fpm, $available);
        self::assertCount(1, $available);
    }

    #[Test]
    public function is_available_returns_true_for_fpm_always(): void
    {
        $resolver = $this->createResolver();

        self::assertTrue($resolver->isAvailable(RuntimeType::Fpm));
    }

    #[Test]
    public function is_available_returns_false_for_missing_frankenphp(): void
    {
        $resolver = $this->createResolver();

        self::assertFalse($resolver->isAvailable(RuntimeType::FrankenPhp));
    }

    #[Test]
    public function is_available_returns_true_for_present_frankenphp(): void
    {
        $resolver = $this->createResolver(frankenPhp: true);

        self::assertTrue($resolver->isAvailable(RuntimeType::FrankenPhp));
    }

    #[Test]
    public function is_available_returns_false_for_missing_roadrunner(): void
    {
        $resolver = $this->createResolver();

        self::assertFalse($resolver->isAvailable(RuntimeType::RoadRunner));
    }

    #[Test]
    public function is_available_returns_true_for_present_roadrunner(): void
    {
        $resolver = $this->createResolver(roadRunner: true);

        self::assertTrue($resolver->isAvailable(RuntimeType::RoadRunner));
    }

    #[Test]
    public function is_available_returns_false_for_missing_persistent(): void
    {
        $resolver = $this->createResolver();

        self::assertFalse($resolver->isAvailable(RuntimeType::Persistent));
    }

    #[Test]
    public function is_available_returns_true_for_present_persistent(): void
    {
        $resolver = $this->createResolver(sockets: true);

        self::assertTrue($resolver->isAvailable(RuntimeType::Persistent));
    }
}
