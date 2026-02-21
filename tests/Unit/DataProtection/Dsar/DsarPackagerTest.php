<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarAttachment;
use Pulsar\DataProtection\Dsar\DsarDataSet;
use Pulsar\DataProtection\Dsar\DsarPackager;
use Pulsar\DataProtection\Dsar\DsarRequest;
use Pulsar\DataProtection\Dsar\DsarStatus;
use ZipArchive;

/**
 * Tests for the DSAR data packager.
 *
 * All ZIP files are written into sys_get_temp_dir() which the OS cleans up.
 */
#[CoversClass(DsarPackager::class)]
final class DsarPackagerTest extends TestCase
{
    #[Test]
    public function packageCreatesZipFile(): void
    {
        $packager = new DsarPackager(sys_get_temp_dir());
        $request = $this->makeRequest('pkg-1');
        $dataSets = [
            new DsarDataSet('auth', 'profile', [['email' => 'user@example.com']]),
        ];

        $path = $packager->package($request, $dataSets);

        self::assertFileExists($path);
        self::assertStringEndsWith('.zip', $path);
    }

    #[Test]
    public function packageContainsManifest(): void
    {
        $packager = new DsarPackager(sys_get_temp_dir());
        $request = $this->makeRequest('pkg-2');
        $dataSets = [
            new DsarDataSet('auth', 'profile', [['name' => 'Alice']]),
        ];

        $path = $packager->package($request, $dataSets);

        $zip = new ZipArchive();
        $zip->open($path);
        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        self::assertIsString($manifest);

        /** @var array{request_id: string, subject_id: string, sources: list<array{name: string}>} $decoded */
        $decoded = json_decode($manifest, true);

        self::assertIsArray($decoded);
        self::assertSame('pkg-2', $decoded['request_id']);
        self::assertSame('sub-pkg-2', $decoded['subject_id']);
        self::assertCount(1, $decoded['sources']);
        self::assertSame('auth', $decoded['sources'][0]['name']);
    }

    #[Test]
    public function packageContainsDataRecords(): void
    {
        $packager = new DsarPackager(sys_get_temp_dir());
        $request = $this->makeRequest('pkg-3');
        $records = [
            ['email' => 'user@example.com', 'name' => 'Bob'],
        ];
        $dataSets = [
            new DsarDataSet('auth', 'profile', $records),
        ];

        $path = $packager->package($request, $dataSets);

        $zip = new ZipArchive();
        $zip->open($path);
        $data = $zip->getFromName('data/auth/profile.json');
        $zip->close();

        self::assertIsString($data);

        /** @var list<array{email: string, name: string}> $decoded */
        $decoded = json_decode($data, true);

        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('Bob', $decoded[0]['name']);
    }

    #[Test]
    public function packageContainsAttachments(): void
    {
        $packager = new DsarPackager(sys_get_temp_dir());
        $request = $this->makeRequest('pkg-4');
        $dataSets = [
            new DsarDataSet(
                'media',
                'uploads',
                [],
                [new DsarAttachment('photo.jpg', 'image-binary-data', 'image/jpeg')],
            ),
        ];

        $path = $packager->package($request, $dataSets);

        $zip = new ZipArchive();
        $zip->open($path);
        $attachment = $zip->getFromName('attachments/media/photo.jpg');
        $zip->close();

        self::assertSame('image-binary-data', $attachment);
    }

    #[Test]
    public function packageHandlesMultipleDataSets(): void
    {
        $packager = new DsarPackager(sys_get_temp_dir());
        $request = $this->makeRequest('pkg-5');
        $dataSets = [
            new DsarDataSet('auth', 'profile', [['email' => 'a@b.com']]),
            new DsarDataSet('orders', 'transactions', [['id' => 'ord-1']]),
            DsarDataSet::empty('analytics', 'events'),
        ];

        $path = $packager->package($request, $dataSets);

        $zip = new ZipArchive();
        $zip->open($path);

        self::assertIsString($zip->getFromName('data/auth/profile.json'));
        self::assertIsString($zip->getFromName('data/orders/transactions.json'));
        // Empty data set should not have a data file
        self::assertFalse($zip->getFromName('data/analytics/events.json'));

        $manifestJson = $zip->getFromName('manifest.json');
        self::assertIsString($manifestJson);

        /** @var array{sources: list<array{record_count: int}>} $manifest */
        $manifest = json_decode($manifestJson, true);

        self::assertIsArray($manifest);
        self::assertCount(3, $manifest['sources']);
        self::assertSame(0, $manifest['sources'][2]['record_count']);

        $zip->close();
    }

    #[Test]
    public function packageCreatesOutputDirectoryIfMissing(): void
    {
        $nestedDir = sys_get_temp_dir() . '/dsar-pkg-nest-' . bin2hex(random_bytes(4)) . '/deep';
        $packager = new DsarPackager($nestedDir);
        $request = $this->makeRequest('pkg-6');
        $dataSets = [DsarDataSet::empty('source', 'cat')];

        $path = $packager->package($request, $dataSets);

        self::assertFileExists($path);
        self::assertTrue(is_dir($nestedDir));
    }

    #[Test]
    public function packageFilenameIncludesRequestIdAndDate(): void
    {
        $packager = new DsarPackager(sys_get_temp_dir());
        $request = $this->makeRequest('test-id');
        $dataSets = [DsarDataSet::empty('src', 'cat')];

        $path = $packager->package($request, $dataSets);
        $filename = basename($path);

        self::assertStringStartsWith('dsar-test-id-', $filename);
        self::assertStringEndsWith('.zip', $filename);
        // Date portion should be 8 digits (Ymd)
        self::assertMatchesRegularExpression('/^dsar-test-id-\d{8}\.zip$/', $filename);
    }

    #[Test]
    public function manifestIncludesAttachmentCount(): void
    {
        $packager = new DsarPackager(sys_get_temp_dir());
        $request = $this->makeRequest('pkg-7');
        $dataSets = [
            new DsarDataSet(
                'media',
                'files',
                [['id' => 'f1']],
                [
                    new DsarAttachment('a.txt', 'content-a', 'text/plain'),
                    new DsarAttachment('b.txt', 'content-b', 'text/plain'),
                ],
            ),
        ];

        $path = $packager->package($request, $dataSets);

        $zip = new ZipArchive();
        $zip->open($path);
        $manifestJson = $zip->getFromName('manifest.json');
        $zip->close();

        self::assertIsString($manifestJson);

        /** @var array{sources: list<array{record_count: int, attachment_count: int}>} $manifest */
        $manifest = json_decode($manifestJson, true);

        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['sources'][0]['record_count']);
        self::assertSame(2, $manifest['sources'][0]['attachment_count']);
    }

    private function makeRequest(string $id): DsarRequest
    {
        return new DsarRequest(
            id: $id,
            subjectId: 'sub-' . $id,
            email: $id . '@example.com',
            status: DsarStatus::Processing,
            createdAt: new DateTimeImmutable('-5 days'),
            deadline: new DateTimeImmutable('+25 days'),
        );
    }
}
