<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;

#[CoversClass(ExportBundle::class)]
#[CoversClass(ExportOptions::class)]
final class ExportBundleTest extends TestCase
{
    // ── Scope filtering ─────────────────────────────────────────────

    #[Test]
    public function test_scope_filtering_only_requested_types(): void
    {
        $bundle = new ExportBundle(
            data: [
                'content' => [['id' => 'c1', 'title' => 'Page']],
            ],
            evidenceHash: 'hash-abc',
            createdAt: new DateTimeImmutable(),
            scope: ['content'],
            piiIncluded: false,
        );

        self::assertSame(['content'], $bundle->scope);
        self::assertArrayHasKey('content', $bundle->data);
        self::assertArrayNotHasKey('taxonomies', $bundle->data);
        self::assertArrayNotHasKey('menus', $bundle->data);
    }

    #[Test]
    public function test_multiple_scopes(): void
    {
        $bundle = new ExportBundle(
            data: [
                'content' => [['id' => 'c1']],
                'taxonomies' => [['id' => 't1']],
                'menus' => [['id' => 'm1']],
            ],
            evidenceHash: 'hash-xyz',
            createdAt: new DateTimeImmutable(),
            scope: ['content', 'taxonomies', 'menus'],
            piiIncluded: false,
        );

        self::assertCount(3, $bundle->scope);
        self::assertArrayHasKey('content', $bundle->data);
        self::assertArrayHasKey('taxonomies', $bundle->data);
        self::assertArrayHasKey('menus', $bundle->data);
    }

    // ── PII redaction ───────────────────────────────────────────────

    #[Test]
    public function test_pii_redaction_flag(): void
    {
        $bundleWithPii = new ExportBundle(
            data: ['content' => [['email' => 'user@example.com']]],
            evidenceHash: 'hash-1',
            createdAt: new DateTimeImmutable(),
            scope: ['content'],
            piiIncluded: true,
        );

        $bundleWithoutPii = new ExportBundle(
            data: ['content' => [['email' => '[redacted]']]],
            evidenceHash: 'hash-2',
            createdAt: new DateTimeImmutable(),
            scope: ['content'],
            piiIncluded: false,
        );

        self::assertTrue($bundleWithPii->piiIncluded);
        self::assertFalse($bundleWithoutPii->piiIncluded);
        /** @var list<array<string, mixed>> $content */
        $content = $bundleWithoutPii->data['content'];
        self::assertSame('[redacted]', $content[0]['email']);
    }

    // ── Evidence hash ───────────────────────────────────────────────

    #[Test]
    public function test_evidence_hash_is_non_empty(): void
    {
        $bundle = new ExportBundle(
            data: ['content' => []],
            evidenceHash: hash('xxh128', json_encode(['content' => []], JSON_THROW_ON_ERROR)),
            createdAt: new DateTimeImmutable(),
            scope: ['content'],
            piiIncluded: false,
        );

        self::assertNotEmpty($bundle->evidenceHash);
    }

    // ── JSON structure ──────────────────────────────────────────────

    #[Test]
    public function test_export_bundle_json_structure(): void
    {
        $now = new DateTimeImmutable();

        $bundle = new ExportBundle(
            data: [
                'content' => [['id' => 'c1', 'title' => 'Hello']],
                'settings' => ['site_name' => 'Test Site'],
            ],
            evidenceHash: 'blake2b-hash-value',
            createdAt: $now,
            scope: ['content', 'settings'],
            piiIncluded: false,
        );

        self::assertNotEmpty($bundle->data);
        self::assertNotEmpty($bundle->evidenceHash);
        self::assertInstanceOf(DateTimeImmutable::class, $bundle->createdAt);
        self::assertIsList($bundle->scope);
        self::assertFalse($bundle->piiIncluded);
    }

    // ── ExportOptions validation ────────────────────────────────────

    #[Test]
    public function test_export_options_from_array_valid(): void
    {
        $options = ExportOptions::fromArray([
            'scope' => ['content', 'taxonomies'],
            'locales' => ['en', 'fr'],
            'include_pii' => true,
            'tenant_id' => 'tenant-001',
        ]);

        self::assertSame(['content', 'taxonomies'], $options->scope);
        self::assertSame(['en', 'fr'], $options->locales);
        self::assertTrue($options->includePii);
        self::assertSame('tenant-001', $options->tenantId);
    }

    #[Test]
    public function test_export_options_from_array_invalid_scope_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExportOptions::fromArray([
            'scope' => ['invalid_scope'],
        ]);
    }
}
