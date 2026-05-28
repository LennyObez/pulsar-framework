<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console\Evidence;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Command\Console\JsonOutputHelper;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipelineInterface;

use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Tests redaction policies against sample payloads.
 */
#[Internal]
final class RedactionTestCommand extends Command
{
    public function __construct(
        private readonly RedactionPipelineInterface $pipeline,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:evidence:redaction:test';
        $this->description = 'Test redaction policies against sample data';
        $this->addOption('payload', 'JSON payload to test', 'p');
        $this->addOption('type', 'Event type to test against', 't', EventType::HttpRequest->value);
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');
        $payloadJson = $input->getNullableStringOption('payload');
        $typeNameStr = $input->getStringOption('type', EventType::HttpRequest->value);

        $eventType = EventType::tryFrom($typeNameStr);

        if ($eventType === null) {
            $error = sprintf('Unknown event type: %s', $typeNameStr);

            if ($isJson) {
                $output->writeln(JsonOutputHelper::encode('studio:console:evidence:redaction:test', false, ['error' => $error]));
            } else {
                $output->writeln($error);
            }

            return ExitCode::Error->value;
        }

        if ($payloadJson === null) {
            $payload = $this->samplePayload();
        } else {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
        }

        $redacted = $this->pipeline->redact($payload, $eventType);

        $data = [
            'event_type' => $eventType->value,
            'original' => $payload,
            'redacted' => $redacted,
        ];

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:evidence:redaction:test', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln('Redaction Test');
        $output->writeln(str_repeat('=', 50));
        $output->writeln(sprintf('  Event type: %s', $eventType->value));
        $output->writeln();
        $output->writeln('  Original:');
        $this->printPayload($output, $payload, '    ');
        $output->writeln();
        $output->writeln('  Redacted:');
        $this->printPayload($output, $redacted, '    ');

        return ExitCode::Success->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function samplePayload(): array
    {
        return [
            'url' => 'https://user:secret@example.com/api',
            'headers' => [
                'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.test',
                'X-Api-Key' => 'test_key_placeholder_value',
            ],
            'password' => 'my-secret-password',
            'dsn' => 'mysql://root:hunter2@db.example.com/app',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function printPayload(OutputInterface $output, array $payload, string $indent): void
    {
        /** @var mixed $value */
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $output->writeln(sprintf('%s%s:', $indent, $key));
                /** @var array<string, mixed> $value */
                $this->printPayload($output, $value, $indent . '  ');
            } else {
                /** @var scalar $value */
                $output->writeln(sprintf('%s%s: %s', $indent, $key, $value));
            }
        }
    }
}
