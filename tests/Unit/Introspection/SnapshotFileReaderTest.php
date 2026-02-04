<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Internal\SnapshotFileReader;

#[CoversClass(SnapshotFileReader::class)]
final class SnapshotFileReaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_snapshot_test_' . uniqid();
        mkdir($this->tempDir . '/tools/api', 0o777, true);
    }

    protected function tearDown(): void
    {
        $snapshotPath = $this->tempDir . '/tools/api/public-api.snapshot.json';

        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
        }

        if (is_dir($this->tempDir . '/tools/api')) {
            rmdir($this->tempDir . '/tools/api');
        }

        if (is_dir($this->tempDir . '/tools')) {
            rmdir($this->tempDir . '/tools');
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function readParsesValidSnapshot(): void
    {
        $snapshot = [
            'api_classes' => [
                'Pulsar\\Http\\Method' => [
                    'since' => '1.0.0',
                    'methods' => ['isSafe', 'isIdempotent'],
                    'constants' => ['GET', 'POST'],
                ],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/tools/api/public-api.snapshot.json',
            json_encode($snapshot, JSON_THROW_ON_ERROR),
        );

        $reader = new SnapshotFileReader($this->tempDir);
        $warnings = [];
        $result = $reader->read($warnings);

        self::assertSame([], $warnings);
        self::assertArrayHasKey('Pulsar\\Http\\Method', $result->classes);
        self::assertSame('1.0.0', $result->classes['Pulsar\\Http\\Method']['since']);
        self::assertSame(['isSafe', 'isIdempotent'], $result->classes['Pulsar\\Http\\Method']['methods']);
    }

    #[Test]
    public function readHandlesMissingFile(): void
    {
        $reader = new SnapshotFileReader($this->tempDir . '/nonexistent');
        $warnings = [];
        $result = $reader->read($warnings);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('not found', $warnings[0]);
        self::assertSame([], $result->classes);
    }

    #[Test]
    public function readHandlesInvalidJson(): void
    {
        file_put_contents(
            $this->tempDir . '/tools/api/public-api.snapshot.json',
            '{invalid json!!!',
        );

        $reader = new SnapshotFileReader($this->tempDir);
        $warnings = [];
        $result = $reader->read($warnings);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('invalid JSON', $warnings[0]);
        self::assertSame([], $result->classes);
    }
}
