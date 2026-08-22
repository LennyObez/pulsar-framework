<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Command\Console\JsonOutputHelper;

#[CoversClass(JsonOutputHelper::class)]
final class JsonOutputHelperTest extends TestCase
{
    #[Test]
    public function encodeProducesValidJsonEnvelope(): void
    {
        $json = JsonOutputHelper::encode('studio:status', true, ['enabled' => true]);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('studio:status', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertSame(['enabled' => true], $decoded['data']);
    }

    #[Test]
    public function encodeWithFailure(): void
    {
        $json = JsonOutputHelper::encode('studio:verify', false, ['error' => 'chain broken']);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['success']);
        self::assertSame('chain broken', $decoded['data']['error']);
    }

    #[Test]
    public function formatJsonProducesPrettyPrintedOutput(): void
    {
        $json = JsonOutputHelper::formatJson(['key' => 'value', 'count' => 42]);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('value', $decoded['key']);
        self::assertSame(42, $decoded['count']);
        self::assertStringContainsString("\n", $json);
    }

    #[Test]
    public function writeErrorInJsonMode(): void
    {
        $output = $this->createStub(OutputInterface::class);
        $written = [];

        $output->method('writeln')->willReturnCallback(function (string $line) use (&$written): void {
            $written[] = $line;
        });

        JsonOutputHelper::writeError($output, true, 'NOT_FOUND', 'Event not found');

        self::assertCount(1, $written);
        $decoded = json_decode($written[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('NOT_FOUND', $decoded['error']);
        self::assertSame('Event not found', $decoded['message']);
    }

    #[Test]
    public function writeErrorInPlainMode(): void
    {
        $output = $this->createStub(OutputInterface::class);
        $errors = [];

        $output->method('errorln')->willReturnCallback(function (string $line) use (&$errors): void {
            $errors[] = $line;
        });

        JsonOutputHelper::writeError($output, false, 'ERR', 'Something went wrong');

        self::assertCount(1, $errors);
        self::assertSame('Something went wrong', $errors[0]);
    }

    #[Test]
    public function writeFieldFormatsWithLabelWidth(): void
    {
        $output = $this->createStub(OutputInterface::class);
        $lines = [];

        $output->method('writeln')->willReturnCallback(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        JsonOutputHelper::writeField($output, 'Status', 'enabled');

        self::assertCount(1, $lines);
        self::assertStringContainsString('Status:', $lines[0]);
        self::assertStringContainsString('enabled', $lines[0]);
    }

    #[Test]
    public function writeFieldRespectsCustomLabelWidth(): void
    {
        $output = $this->createStub(OutputInterface::class);
        $lines = [];

        $output->method('writeln')->willReturnCallback(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        JsonOutputHelper::writeField($output, 'DB', 'sqlite', labelWidth: 5);

        self::assertCount(1, $lines);
        self::assertStringContainsString('DB:', $lines[0]);
    }

    #[Test]
    public function encodeUnescapesSlashes(): void
    {
        $json = JsonOutputHelper::encode('test', true, ['path' => '/api/v1/users']);

        self::assertStringContainsString('/api/v1/users', $json);
        self::assertStringNotContainsString('\\/api\\/v1\\/users', $json);
    }
}
