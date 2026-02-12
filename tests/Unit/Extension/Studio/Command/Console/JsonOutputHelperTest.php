<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\JsonOutputHelper;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(JsonOutputHelper::class)]
final class JsonOutputHelperTest extends TestCase
{
    #[Test]
    public function encodeCreatesJsonEnvelope(): void
    {
        $json = JsonOutputHelper::encode('test:command', true, ['key' => 'value']);

        /** @var array{command: string, success: bool, data: array{key: string}} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('test:command', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertSame('value', $decoded['data']['key']);
    }

    #[Test]
    public function encodeWithFailureStatus(): void
    {
        $json = JsonOutputHelper::encode('test:command', false, ['error' => 'something failed']);

        /** @var array{command: string, success: bool, data: array{error: string}} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['success']);
        self::assertSame('something failed', $decoded['data']['error']);
    }

    #[Test]
    public function formatJsonProducesPrettyOutput(): void
    {
        $json = JsonOutputHelper::formatJson(['name' => 'test', 'count' => 42]);

        /** @var array{name: string, count: int} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('test', $decoded['name']);
        self::assertSame(42, $decoded['count']);
        self::assertStringContainsString("\n", $json);
    }

    #[Test]
    public function writeErrorAsJson(): void
    {
        $output = new BufferedOutput();

        JsonOutputHelper::writeError($output, true, 'not_found', 'Resource not found');

        /** @var array{error: string, message: string} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('not_found', $decoded['error']);
        self::assertSame('Resource not found', $decoded['message']);
    }

    #[Test]
    public function writeErrorAsPlainText(): void
    {
        $output = new BufferedOutput();

        JsonOutputHelper::writeError($output, false, 'not_found', 'Resource not found');

        self::assertStringContainsString('Resource not found', $output->errorBuffer);
        self::assertEmpty($output->buffer);
    }

    #[Test]
    public function writeFieldFormatsLabelAndValue(): void
    {
        $output = new BufferedOutput();

        JsonOutputHelper::writeField($output, 'Status', 'running');

        self::assertStringContainsString('Status:', $output->buffer);
        self::assertStringContainsString('running', $output->buffer);
    }

    #[Test]
    public function writeFieldWithCustomWidth(): void
    {
        $output = new BufferedOutput();

        JsonOutputHelper::writeField($output, 'Key', 'value', 20);

        self::assertStringContainsString('Key:', $output->buffer);
        self::assertStringContainsString('value', $output->buffer);
    }

    #[Test]
    public function encodeUnescapesSlashes(): void
    {
        $json = JsonOutputHelper::encode('test', true, ['url' => 'https://example.com/path']);

        self::assertStringNotContainsString('\\/', $json);
        self::assertStringContainsString('https://example.com/path', $json);
    }
}
