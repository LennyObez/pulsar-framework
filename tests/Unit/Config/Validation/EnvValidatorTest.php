<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Validation\EnvValidator;

#[CoversClass(EnvValidator::class)]
final class EnvValidatorTest extends TestCase
{
    /** @var list<string> Temp files to clean up */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
    }

    #[Test]
    public function parse_required_keys_from_env_example(): void
    {
        $envExample = $this->createTempEnvFile(<<<'ENV'
            APP_NAME=Pulsar
            APP_ENV=local
            APP_KEY=
            DATABASE_URL=
            REDIS_HOST=localhost
            SECRET_TOKEN=
            ENV);

        $validator = new EnvValidator();
        $keys = $validator->parseRequiredKeys($envExample);

        self::assertContains('APP_KEY', $keys);
        self::assertContains('DATABASE_URL', $keys);
        self::assertContains('SECRET_TOKEN', $keys);
        self::assertNotContains('APP_NAME', $keys);
        self::assertNotContains('APP_ENV', $keys);
        self::assertNotContains('REDIS_HOST', $keys);
    }

    #[Test]
    public function parse_all_keys_from_env_example(): void
    {
        $envExample = $this->createTempEnvFile(<<<'ENV'
            APP_NAME=Pulsar
            APP_KEY=
            DATABASE_URL=
            ENV);

        $validator = new EnvValidator();
        $keys = $validator->parseAllKeys($envExample);

        self::assertContains('APP_NAME', $keys);
        self::assertContains('APP_KEY', $keys);
        self::assertContains('DATABASE_URL', $keys);
    }

    #[Test]
    public function parse_returns_empty_for_nonexistent_file(): void
    {
        $validator = new EnvValidator();

        self::assertSame([], $validator->parseRequiredKeys('/nonexistent/.env.example'));
        self::assertSame([], $validator->parseAllKeys('/nonexistent/.env.example'));
    }

    #[Test]
    public function validate_passes_when_all_required_keys_are_set(): void
    {
        $validator = new EnvValidator();

        $result = $validator->validate(
            ['APP_KEY', 'DATABASE_URL'],
            ['APP_KEY' => 'secret123', 'DATABASE_URL' => 'sqlite://memory'],
        );

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function validate_fails_when_required_keys_are_missing(): void
    {
        $validator = new EnvValidator();

        $result = $validator->validate(
            ['APP_KEY', 'DATABASE_URL'],
            ['APP_KEY' => 'secret123'],
        );

        self::assertFalse($result->isValid());
        self::assertSame(1, $result->errorCount());
        self::assertStringContainsString('DATABASE_URL', $result->messages()[0]);
    }

    #[Test]
    public function validate_fails_for_empty_string_values(): void
    {
        $validator = new EnvValidator();

        $result = $validator->validate(
            ['APP_KEY'],
            ['APP_KEY' => ''],
        );

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function validate_fails_for_whitespace_only_values(): void
    {
        $validator = new EnvValidator();

        $result = $validator->validate(
            ['APP_KEY'],
            ['APP_KEY' => '   '],
        );

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function find_missing_returns_missing_keys(): void
    {
        $validator = new EnvValidator();

        $missing = $validator->findMissing(
            ['APP_KEY', 'DB_HOST', 'REDIS_URL'],
            ['APP_KEY' => 'set', 'REDIS_URL' => ''],
        );

        self::assertContains('DB_HOST', $missing);
        self::assertContains('REDIS_URL', $missing);
        self::assertNotContains('APP_KEY', $missing);
    }

    #[Test]
    public function find_missing_returns_empty_when_all_set(): void
    {
        $validator = new EnvValidator();

        $missing = $validator->findMissing(
            ['APP_KEY'],
            ['APP_KEY' => 'value'],
        );

        self::assertSame([], $missing);
    }

    /**
     * Create a temporary file with the given content and register it for cleanup.
     */
    private function createTempEnvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_env_');
        self::assertNotFalse($path, 'Failed to create temp file');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
