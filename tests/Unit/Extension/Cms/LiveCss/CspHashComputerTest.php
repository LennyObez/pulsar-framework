<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\LiveCss\CspHashComputer;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;

use function base64_encode;
use function hash;

#[CoversClass(CspHashComputer::class)]
final class CspHashComputerTest extends TestCase
{
    // ── SHA-256 computation against known vector ────────────────────

    #[Test]
    public function sha256ComputationAgainstKnownVector(): void
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
    public function resultIsBase64Encoded(): void
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
    public function outputFormatSha256Prefix(): void
    {
        $computer = $this->createComputer();
        $result = $computer->computeHash('div { margin: 0; }');

        self::assertStringStartsWith('sha256-', $result);
    }

    // ── Empty string ────────────────────────────────────────────────

    #[Test]
    public function emptyInputProducesValidHash(): void
    {
        $computer = $this->createComputer();
        $result = $computer->computeHash('');

        $expectedHash = base64_encode(hash('sha256', '', true));

        self::assertSame("sha256-{$expectedHash}", $result);
    }

    // ── Different inputs produce different hashes ───────────────────

    #[Test]
    public function differentInputsProduceDifferentHashes(): void
    {
        $computer = $this->createComputer();

        $hash1 = $computer->computeHash('body { color: red; }');
        $hash2 = $computer->computeHash('body { color: blue; }');

        self::assertNotSame($hash1, $hash2);
    }

    // ── Same input produces same hash ───────────────────────────────

    #[Test]
    public function sameInputProducesSameHash(): void
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
