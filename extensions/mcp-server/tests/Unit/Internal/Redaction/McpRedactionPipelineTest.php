<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Redaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Internal\Redaction\McpRedactionPipeline;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

final class McpRedactionPipelineTest extends TestCase
{
    private SensitiveDataScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new SensitiveDataScrubber();
    }

    #[Test]
    public function redacts_env_var_patterns(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber);

        $result = $pipeline->redactString('PULSAR_MASTER_KEY=supersecret123');

        self::assertSame('[REDACTED]', $result);
    }

    #[Test]
    public function redacts_db_password_pattern(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber);

        $result = $pipeline->redactString('DB_PASSWORD=myp@ssw0rd!');

        self::assertSame('[REDACTED]', $result);
    }

    #[Test]
    public function redacts_connection_strings(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber);

        $result = $pipeline->redactString('mysql://root:secret@localhost/db');

        self::assertSame('mysql://[REDACTED]@localhost/db', $result);
    }

    #[Test]
    public function redacts_bearer_tokens(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber);

        $result = $pipeline->redactString('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.test');

        self::assertStringContainsString('Bearer [REDACTED]', $result);
    }

    #[Test]
    public function redacts_known_secret_values(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber, ['my-secret-value']);

        $result = $pipeline->redactString('The key is my-secret-value and more text');

        self::assertStringContainsString('[REDACTED]', $result);
        self::assertStringNotContainsString('my-secret-value', $result);
    }

    #[Test]
    public function preserves_non_sensitive_text(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber);

        $result = $pipeline->redactString('Hello world, nothing sensitive here');

        self::assertSame('Hello world, nothing sensitive here', $result);
    }

    #[Test]
    public function redact_produces_new_tool_result(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber);

        $input = new ToolResult(
            structuredContent: ['key' => 'value'],
            textContent: 'DB_PASSWORD=secret',
            isError: false,
            meta: ['tool' => 'test'],
        );

        $output = $pipeline->redact($input);

        self::assertSame('[REDACTED]', $output->textContent);
        self::assertFalse($output->isError);
        self::assertSame(['tool' => 'test'], $output->meta);
    }

    #[Test]
    public function skips_empty_known_secrets(): void
    {
        $pipeline = new McpRedactionPipeline($this->scrubber, ['']);

        $result = $pipeline->redactString('Some normal text');

        self::assertSame('Some normal text', $result);
    }
}
