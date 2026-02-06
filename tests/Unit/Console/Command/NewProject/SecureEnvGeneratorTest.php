<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\EnvironmentPreset;
use Pulsar\Console\Command\NewProject\SecureEnvGenerator;

#[CoversClass(SecureEnvGenerator::class)]
final class SecureEnvGeneratorTest extends TestCase
{
    private SecureEnvGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SecureEnvGenerator();
    }

    #[Test]
    public function it_generates_env_content_with_app_name(): void
    {
        $content = $this->generator->generate('my-app', EnvironmentPreset::Local);

        self::assertStringContainsString('APP_NAME=my-app', $content);
    }

    #[Test]
    public function it_sets_local_env_values(): void
    {
        $content = $this->generator->generate('test-app', EnvironmentPreset::Local);

        self::assertStringContainsString('APP_ENV=local', $content);
        self::assertStringContainsString('APP_DEBUG=true', $content);
        self::assertStringContainsString('APP_URL=http://localhost:8000', $content);
    }

    #[Test]
    public function it_sets_staging_env_values(): void
    {
        $content = $this->generator->generate('test-app', EnvironmentPreset::Staging);

        self::assertStringContainsString('APP_ENV=staging', $content);
        self::assertStringContainsString('APP_DEBUG=false', $content);
        self::assertStringContainsString('APP_URL=https://example.com', $content);
    }

    #[Test]
    public function it_sets_production_env_values(): void
    {
        $content = $this->generator->generate('test-app', EnvironmentPreset::Production);

        self::assertStringContainsString('APP_ENV=production', $content);
        self::assertStringContainsString('APP_DEBUG=false', $content);
        self::assertStringContainsString('APP_URL=https://example.com', $content);
    }

    #[Test]
    public function it_generates_an_app_key_with_base64_prefix(): void
    {
        $content = $this->generator->generate('test-app', EnvironmentPreset::Local);

        self::assertMatchesRegularExpression('/APP_KEY=base64:[A-Za-z0-9+\/=]+/', $content);
    }

    #[Test]
    public function it_generates_a_master_key_as_hex(): void
    {
        $content = $this->generator->generate('test-app', EnvironmentPreset::Local);

        // Master key should be 64 hex chars (32 bytes)
        self::assertMatchesRegularExpression('/PULSAR_MASTER_KEY=[a-f0-9]{64}/', $content);
    }

    #[Test]
    public function it_generates_unique_keys_per_invocation(): void
    {
        $first = $this->generator->generate('app', EnvironmentPreset::Local);
        $second = $this->generator->generate('app', EnvironmentPreset::Local);

        // Extract keys for comparison
        preg_match('/APP_KEY=(.+)/', $first, $firstKey);
        preg_match('/APP_KEY=(.+)/', $second, $secondKey);

        self::assertNotSame($firstKey[1], $secondKey[1]);
    }

    #[Test]
    public function it_generates_unique_master_keys_per_invocation(): void
    {
        $first = $this->generator->generate('app', EnvironmentPreset::Local);
        $second = $this->generator->generate('app', EnvironmentPreset::Local);

        preg_match('/PULSAR_MASTER_KEY=(.+)/', $first, $firstKey);
        preg_match('/PULSAR_MASTER_KEY=(.+)/', $second, $secondKey);

        self::assertNotSame($firstKey[1], $secondKey[1]);
    }

    #[Test]
    public function it_ends_with_a_newline(): void
    {
        $content = $this->generator->generate('test-app', EnvironmentPreset::Local);

        self::assertStringEndsWith("\n", $content);
    }

    #[Test]
    public function it_contains_all_required_env_variables(): void
    {
        $content = $this->generator->generate('test-app', EnvironmentPreset::Local);

        $requiredKeys = [
            'APP_NAME',
            'APP_ENV',
            'APP_DEBUG',
            'APP_URL',
            'APP_KEY',
            'PULSAR_MASTER_KEY',
        ];

        foreach ($requiredKeys as $key) {
            self::assertStringContainsString($key . '=', $content, "Missing env variable: {$key}");
        }
    }

    #[Test]
    public function it_preserves_the_app_name_verbatim(): void
    {
        $content = $this->generator->generate('My Complex App Name', EnvironmentPreset::Local);

        self::assertStringContainsString('APP_NAME=My Complex App Name', $content);
    }
}
