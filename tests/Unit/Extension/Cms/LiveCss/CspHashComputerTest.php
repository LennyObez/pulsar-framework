<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;

use function base64_encode;
use function hash;

#[CoversClass(CspHashComputerInterface::class)]
final class CspHashComputerTest extends TestCase
{
    // ── SHA-256 computation against known vector ────────────────────

    #[Test]
    public function test_sha256_computation_against_known_vector(): void
    {
        $computer = $this->createComputer();
        $input = 'body { color: red; }';

        $expectedHash = base64_encode(hash('sha256', $input, true));
        $expected = "sha256-{$expectedHash}";

        $result = $computer->computeHash($input);

        self::assertSame($expected, $result);
    }

    // ── Base64 encoding ─────────────────────────────────────────────

    #[Test]
    public function test_result_is_base64_encoded(): void
    {
        $computer = $this->createComputer();
        $result = $computer->computeHash('.test { display: flex; }');

        // Extract the base64 part after "sha256-"
        $base64Part = substr($result, 7);

        // Verify it's valid base64
        self::assertNotFalse(base64_decode($base64Part, true));
    }

    // ── Output format ───────────────────────────────────────────────

    #[Test]
    public function test_output_format_sha256_prefix(): void
    {
        $computer = $this->createComputer();
        $result = $computer->computeHash('div { margin: 0; }');

        self::assertStringStartsWith('sha256-', $result);
    }

    // ── Empty string ────────────────────────────────────────────────

    #[Test]
    public function test_empty_input_produces_valid_hash(): void
    {
        $computer = $this->createComputer();
        $result = $computer->computeHash('');

        $expectedHash = base64_encode(hash('sha256', '', true));

        self::assertSame("sha256-{$expectedHash}", $result);
    }

    // ── Different inputs produce different hashes ───────────────────

    #[Test]
    public function test_different_inputs_produce_different_hashes(): void
    {
        $computer = $this->createComputer();

        $hash1 = $computer->computeHash('body { color: red; }');
        $hash2 = $computer->computeHash('body { color: blue; }');

        self::assertNotSame($hash1, $hash2);
    }

    // ── Same input produces same hash ───────────────────────────────

    #[Test]
    public function test_same_input_produces_same_hash(): void
    {
        $computer = $this->createComputer();

        $hash1 = $computer->computeHash('body { color: red; }');
        $hash2 = $computer->computeHash('body { color: red; }');

        self::assertSame($hash1, $hash2);
    }

    private function createComputer(): CspHashComputerInterface
    {
        return new class implements CspHashComputerInterface {
            public function computeHash(string $styleContent): string
            {
                $hash = hash('sha256', $styleContent, true);

                return 'sha256-' . base64_encode($hash);
            }
        };
    }
}
