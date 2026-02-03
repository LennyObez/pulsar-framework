<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\Evidence\RedactionTestCommand;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Studio\Console\Redaction\RedactionPipelineInterface;

#[CoversClass(RedactionTestCommand::class)]
final class RedactionTestCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $pipeline = new RedactionPipeline();
        $command = new RedactionTestCommand($pipeline);

        self::assertSame('studio:console:evidence:redaction:test', $command->name);
        self::assertSame('Test redaction policies against sample data', $command->description);
        self::assertArrayHasKey('payload', $command->options);
        self::assertArrayHasKey('type', $command->options);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('p', $command->options['payload']['shortcut']);
        self::assertSame('t', $command->options['type']['shortcut']);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function executeWithSamplePayloadAsText(): void
    {
        $pipeline = $this->createStub(RedactionPipelineInterface::class);
        $pipeline->method('redact')->willReturnCallback(static function (array $payload, EventType $type): array {
            $payload['password'] = '[REDACTED]';

            return $payload;
        });

        $command = new RedactionTestCommand($pipeline);
        $input = new ArrayInput('studio:console:evidence:redaction:test');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Redaction Test', $this->output->buffer);
        self::assertStringContainsString('Event type: http.request', $this->output->buffer);
        self::assertStringContainsString('Original:', $this->output->buffer);
        self::assertStringContainsString('Redacted:', $this->output->buffer);
    }

    #[Test]
    public function executeWithCustomPayloadAsText(): void
    {
        $pipeline = $this->createStub(RedactionPipelineInterface::class);
        $pipeline->method('redact')->willReturnCallback(static fn(array $payload): array => $payload);

        $command = new RedactionTestCommand($pipeline);
        $customPayload = json_encode(['email' => 'user@example.com', 'token' => 'secret123']);
        $input = new ArrayInput('studio:console:evidence:redaction:test', [], ['payload' => $customPayload]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Redaction Test', $this->output->buffer);
        self::assertStringContainsString('email:', $this->output->buffer);
    }

    #[Test]
    public function executeWithInvalidEventType(): void
    {
        $pipeline = new RedactionPipeline();
        $command = new RedactionTestCommand($pipeline);
        $input = new ArrayInput('studio:console:evidence:redaction:test', [], ['type' => 'invalid.type']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Unknown event type: invalid.type', $this->output->buffer);
    }

    #[Test]
    public function executeWithInvalidEventTypeAsJson(): void
    {
        $pipeline = new RedactionPipeline();
        $command = new RedactionTestCommand($pipeline);
        $input = new ArrayInput('studio:console:evidence:redaction:test', [], ['type' => 'bad.type', 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool, data: array{error: string}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:evidence:redaction:test', $json['command']);
        self::assertFalse($json['success']);
        self::assertStringContainsString('Unknown event type', $json['data']['error']);
    }

    #[Test]
    public function executeOutputsJson(): void
    {
        $pipeline = $this->createStub(RedactionPipelineInterface::class);
        $pipeline->method('redact')->willReturnCallback(static function (array $payload): array {
            $payload['password'] = '[REDACTED]';

            return $payload;
        });

        $command = new RedactionTestCommand($pipeline);
        $input = new ArrayInput('studio:console:evidence:redaction:test', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{event_type: string, original: array<string, mixed>, redacted: array<string, mixed>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:evidence:redaction:test', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame('http.request', $json['data']['event_type']);
        self::assertArrayHasKey('original', $json['data']);
        self::assertArrayHasKey('redacted', $json['data']);
    }

    #[Test]
    public function executeWithSpecificEventType(): void
    {
        $pipeline = $this->createStub(RedactionPipelineInterface::class);
        $pipeline->method('redact')->willReturnCallback(static fn(array $payload): array => $payload);

        $command = new RedactionTestCommand($pipeline);
        $input = new ArrayInput('studio:console:evidence:redaction:test', [], ['type' => 'db.query']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Event type: db.query', $this->output->buffer);
    }

    #[Test]
    public function typeOptionDefaultsToHttpRequest(): void
    {
        $pipeline = new RedactionPipeline();
        $command = new RedactionTestCommand($pipeline);

        self::assertSame(EventType::HttpRequest->value, $command->options['type']['default']);
    }
}
