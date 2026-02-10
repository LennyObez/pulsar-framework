<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Redaction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\McpServer\Internal\Redaction\McpRedactionPipeline;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(McpRedactionPipeline::class)]
final class McpRedactionPipelineTest extends TestCase
{
    private McpRedactionPipeline $pipeline;

    protected function setUp(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $this->pipeline = new McpRedactionPipeline($scrubber);
    }

    #[Test]
    public function redactStringScrubesEnvVars(): void
    {
        $input = 'Config: PULSAR_MASTER_KEY=abc123secret DB_PASSWORD=hunter2';

        $output = $this->pipeline->redactString($input);

        self::assertStringNotContainsString('abc123secret', $output);
        self::assertStringNotContainsString('hunter2', $output);
        self::assertStringContainsString('[REDACTED]', $output);
    }

    #[Test]
    public function redactStringScrubesConnectionStrings(): void
    {
        $input = 'DSN: mysql://root:s3cret@localhost/mydb';

        $output = $this->pipeline->redactString($input);

        self::assertStringNotContainsString('root:s3cret', $output);
        self::assertStringContainsString('://[REDACTED]@', $output);
    }

    #[Test]
    public function redactStringScrubesBearerTokens(): void
    {
        $input = 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.test.signature';

        $output = $this->pipeline->redactString($input);

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $output);
        self::assertStringContainsString('Bearer [REDACTED]', $output);
    }

    #[Test]
    public function redactStringScrubesKnownSecretValues(): void
    {
        $scrubber = new SensitiveDataScrubber();
        $pipeline = new McpRedactionPipeline($scrubber, ['my-super-secret-value']);

        $input = 'The value is my-super-secret-value in the output';

        $output = $pipeline->redactString($input);

        self::assertStringNotContainsString('my-super-secret-value', $output);
        self::assertStringContainsString('[REDACTED]', $output);
    }

    #[Test]
    public function collectKnownSecretsFindsSecretKeys(): void
    {
        // Set known env vars with secret-indicating key suffixes
        putenv('TEST_MCP_API_KEY=key-abc-123');
        putenv('TEST_MCP_DB_PASSWORD=hunter2');
        putenv('TEST_MCP_AUTH_TOKEN=tok_xyz');
        putenv('TEST_MCP_JWT_SECRET=jwt-secret-value');
        putenv('TEST_MCP_REDIS_DSN=redis://localhost');

        try {
            $env = Environment::load();
            $secrets = McpRedactionPipeline::collectKnownSecrets($env);

            self::assertContains('key-abc-123', $secrets);
            self::assertContains('hunter2', $secrets);
            self::assertContains('tok_xyz', $secrets);
            self::assertContains('jwt-secret-value', $secrets);
            self::assertContains('redis://localhost', $secrets);
        } finally {
            putenv('TEST_MCP_API_KEY');
            putenv('TEST_MCP_DB_PASSWORD');
            putenv('TEST_MCP_AUTH_TOKEN');
            putenv('TEST_MCP_JWT_SECRET');
            putenv('TEST_MCP_REDIS_DSN');
        }
    }
}
