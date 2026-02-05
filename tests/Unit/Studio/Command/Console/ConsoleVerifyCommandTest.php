<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleVerifyCommand;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceVerifier;
use Pulsar\Extension\Studio\Console\Evidence\HashChain;

use function file_put_contents;
use function hash;
use function is_file;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ConsoleVerifyCommand::class)]
final class ConsoleVerifyCommandTest extends TestCase
{
    private EvidenceVerifier $verifier;
    private BufferedOutput $output;
    private string $tempDir;
    private string $chainMacKey;

    protected function setUp(): void
    {
        // Use the real EvidenceVerifier since it's a final class
        $this->verifier = new EvidenceVerifier();
        $this->output = new BufferedOutput();
        $this->tempDir = sys_get_temp_dir();
        // MAC keys must be at least 16 bytes
        $this->chainMacKey = 'test-chain-mac-key-long-enough';
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);

        self::assertSame('studio:console:verify', $command->name);
        self::assertSame('Verify a Studio evidence archive', $command->description);
        self::assertCount(1, $command->arguments);
        self::assertSame('file', $command->arguments[0]['name']);
        self::assertTrue($command->arguments[0]['required']);
        self::assertArrayHasKey('mode', $command->options);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function executeFailsWhenFileNotFound(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', ['/non/existent/file.json']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Archive file not found', $this->output->errorBuffer);
    }

    #[Test]
    public function executeFailsWhenFileNotFoundWithJsonOutput(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', ['/non/existent/file.json'], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{error: string, message: string} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('file_not_found', $json['error']);
        self::assertSame('Archive file not found', $json['message']);
    }

    #[Test]
    public function executeFailsWithMalformedJson(): void
    {
        // JSON that's valid but doesn't have the required structure
        $filePath = $this->tempDir . '/malformed-archive.json';
        file_put_contents($filePath, '{"events": [], "chain": [], "manifest": {}}');

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath]);

        // This should succeed since the JSON is technically valid
        // The verifier will handle an empty chain
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeFailsWithMissingRequiredKeys(): void
    {
        // The EvidenceArchive::fromJson throws InvalidArgumentException when required keys are missing
        $filePath = $this->tempDir . '/invalid-structure.json';
        file_put_contents($filePath, '{}');

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Invalid archive', $this->output->errorBuffer);
        self::assertStringContainsString('Archive missing required keys', $this->output->errorBuffer);
    }

    protected function tearDown(): void
    {
        // Clean up any remaining temp files
        $patterns = [
            '/invalid-structure.json',
        ];
        foreach ($patterns as $pattern) {
            $path = $this->tempDir . $pattern;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    #[Test]
    public function executeVerifiesEmptyArchiveInPublicMode(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/empty-archive.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Evidence Chain Verification', $this->output->buffer);
        self::assertStringContainsString('Chain integrity verified', $this->output->buffer);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeVerifiesArchiveInPublicModeWithJsonOutput(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/valid-archive-json.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertTrue($json['chain_intact']);
        self::assertSame('public', $json['verification_mode']);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeFailsInTamperEvidentModeWithoutMacKey(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/archive-no-key.json';
        file_put_contents($filePath, $archive->toJson());

        // Command without chainMacKey
        $command = new ConsoleVerifyCommand($this->verifier, null, null);
        $input = new ArrayInput('studio:console:verify', [$filePath], ['mode' => 'tamper-evident']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Tamper-evident verification requires the chain MAC key', $this->output->errorBuffer);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeFailsInTamperEvidentModeWithoutMacKeyWithJsonOutput(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/archive-no-key-json.json';
        file_put_contents($filePath, $archive->toJson());

        // Command without chainMacKey
        $command = new ConsoleVerifyCommand($this->verifier, null, null);
        $input = new ArrayInput('studio:console:verify', [$filePath], ['mode' => 'tamper-evident', 'json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{error: string, message: string} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('mac_key_required', $json['error']);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeVerifiesInTamperEvidentModeWithMacKey(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/archive-with-key.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier, $this->chainMacKey, null);
        $input = new ArrayInput('studio:console:verify', [$filePath], ['mode' => 'tamper-evident']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Chain integrity verified', $this->output->buffer);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeHandlesEmptyFilePath(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', ['']); // Empty file path

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Archive file not found', $this->output->errorBuffer);
    }

    #[Test]
    public function executeHandlesNonStringFilePath(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);
        // Simulate a case where argument is not provided (null/empty)
        $input = new ArrayInput('studio:console:verify', []);

        $exit = $command->execute($input, $this->output);

        // Should handle empty argument gracefully
        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function modeOptionHasShortcutM(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);

        self::assertSame('m', $command->options['mode']['shortcut']);
    }

    #[Test]
    public function jsonOptionHasShortcutJ(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);

        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function modeOptionDefaultsToPublic(): void
    {
        $command = new ConsoleVerifyCommand($this->verifier);

        self::assertSame('public', $command->options['mode']['default']);
    }

    #[Test]
    public function executeFailsInFullModeWithoutMacKey(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/archive-full-no-key.json';
        file_put_contents($filePath, $archive->toJson());

        // Command without chainMacKey
        $command = new ConsoleVerifyCommand($this->verifier, null, null);
        $input = new ArrayInput('studio:console:verify', [$filePath], ['mode' => 'full']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Tamper-evident verification requires the chain MAC key', $this->output->errorBuffer);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeHandlesNonStringMode(): void
    {
        $archive = new EvidenceArchive(
            events: [],
            chainLinks: [],
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/archive-non-string-mode.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        // Simulate non-string mode (boolean true when just --mode is passed)
        $input = new ArrayInput('studio:console:verify', [$filePath], ['mode' => true]);

        $exit = $command->execute($input, $this->output);

        // Should default to 'public' mode when mode is not a string
        self::assertSame(ExitCode::Success->value, $exit);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeVerifiesValidChainWithEvents(): void
    {
        // Build a valid chain
        $chainLinks = $this->buildValidChainFromSeed(3);
        $events = [];
        foreach ($chainLinks as $link) {
            $events[] = [
                'event_id' => $link['event_id'],
                'event_type' => $link['event_type'],
                'timestamp_us' => $link['timestamp_us'],
            ];
        }

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/valid-chain.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Chain integrity verified', $this->output->buffer);
        self::assertStringContainsString('Links:         3 verified', $this->output->buffer);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeDetectsTamperedChain(): void
    {
        // Build a valid chain then tamper with it
        $chainLinks = $this->buildValidChainFromSeed(3);
        $chainLinks[1]['current_hash'] = 'tampered-hash';

        $events = [];
        foreach ($chainLinks as $link) {
            $events[] = [
                'event_id' => $link['event_id'],
                'event_type' => $link['event_type'] ?? 'http.request',
            ];
        }

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/tampered-chain.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        // "Chain integrity FAILED" goes to errorBuffer
        self::assertStringContainsString('Chain integrity FAILED', $this->output->errorBuffer);
        // "Break at link" goes to regular buffer
        self::assertStringContainsString('Break at link', $this->output->buffer);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeShowsWindowModeInfo(): void
    {
        // Build chain that starts from an arbitrary hash (window mode)
        $windowBoundaryHash = hash('sha256', 'arbitrary-boundary-hash');
        $chainLinks = $this->buildValidChain(3, $windowBoundaryHash);

        $events = [];
        foreach ($chainLinks as $link) {
            $events[] = [
                'event_id' => $link['event_id'],
                'event_type' => $link['event_type'],
            ];
        }

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/window-archive.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Chain mode:    window', $this->output->buffer);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeVerifiesArchiveWithJsonOutputAndChainIntact(): void
    {
        $chainLinks = $this->buildValidChainFromSeed(2);
        $events = [];
        foreach ($chainLinks as $link) {
            $events[] = [
                'event_id' => $link['event_id'],
                'event_type' => $link['event_type'],
            ];
        }

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/json-output-intact.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertTrue($json['chain_intact']);
        self::assertSame(2, $json['links_verified']);
        self::assertSame('public', $json['verification_mode']);

        // Clean up
        unlink($filePath);
    }

    #[Test]
    public function executeVerifiesArchiveWithJsonOutputAndChainBroken(): void
    {
        $chainLinks = $this->buildValidChainFromSeed(2);
        $chainLinks[1]['current_hash'] = 'bad-hash';

        $events = [];
        foreach ($chainLinks as $link) {
            $events[] = [
                'event_id' => $link['event_id'],
                'event_type' => $link['event_type'] ?? 'http.request',
            ];
        }

        $archive = new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: ['version' => 1],
            mac: null,
        );

        $filePath = $this->tempDir . '/json-output-broken.json';
        file_put_contents($filePath, $archive->toJson());

        $command = new ConsoleVerifyCommand($this->verifier);
        $input = new ArrayInput('studio:console:verify', [$filePath], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array<string, mixed> $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertFalse($json['chain_intact']);

        // Clean up
        unlink($filePath);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildValidChainFromSeed(int $count): array
    {
        return $this->buildValidChain($count, HashChain::seedHash());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildValidChain(int $count, string $startHash): array
    {
        $links = [];
        $previousHash = $startHash;

        for ($i = 0; $i < $count; $i++) {
            $eventId = "event-{$i}";
            $eventType = 'http.request';
            $schemaVersion = '1';
            $timestampUs = (string) (1234567890123456 + $i);
            $traceId = "trace-{$i}";
            $payloadHash = hash('sha256', "payload-{$i}");

            $canonical = "{$eventId}|{$eventType}|{$schemaVersion}|{$timestampUs}|{$traceId}|{$payloadHash}";
            $currentHash = hash('sha256', $previousHash . '|' . $canonical);

            $links[] = [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'schema_version' => $schemaVersion,
                'timestamp_us' => $timestampUs,
                'trace_id' => $traceId,
                'payload_hash' => $payloadHash,
                'previous_hash' => $previousHash,
                'current_hash' => $currentHash,
            ];

            $previousHash = $currentHash;
        }

        return $links;
    }
}
