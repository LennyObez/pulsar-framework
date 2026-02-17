<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Internal\Tools\CsvContentImporter;

#[CoversClass(CsvContentImporter::class)]
final class CsvContentImporterTest extends TestCase
{
    private SafeHtmlPolicy $safeHtmlPolicy;

    protected function setUp(): void
    {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->safeHtmlPolicy = new SafeHtmlPolicy($auditLogger);
    }

    #[Test]
    public function parse_sanitizes_body_html(): void
    {
        $csv = "title,slug,body,content_type,status\n";
        $csv .= "Test,test,\"<p>Safe</p><script>alert('xss')</script>\",page,draft\n";

        $importer = new CsvContentImporter($this->safeHtmlPolicy);
        $result = $importer->parse($csv);

        self::assertCount(1, $result);
        self::assertStringContainsString('<p>Safe</p>', $result[0]['translation']['body']);
        self::assertStringNotContainsString('<script>', $result[0]['translation']['body']);
    }

    #[Test]
    public function parse_sanitizes_malicious_event_handlers(): void
    {
        $csv = "title,slug,body,content_type,status\n";
        $csv .= "Test,test,\"<img src=x onerror=alert(1)>\",page,draft\n";

        $importer = new CsvContentImporter($this->safeHtmlPolicy);
        $result = $importer->parse($csv);

        self::assertCount(1, $result);
        self::assertStringNotContainsString('onerror', $result[0]['translation']['body']);
    }

    #[Test]
    public function parse_preserves_safe_html(): void
    {
        $csv = "title,slug,body,content_type,status\n";
        $csv .= "Test,test,\"<p>Hello <strong>world</strong></p>\",page,draft\n";

        $importer = new CsvContentImporter($this->safeHtmlPolicy);
        $result = $importer->parse($csv);

        self::assertCount(1, $result);
        self::assertStringContainsString('<p>Hello <strong>world</strong></p>', $result[0]['translation']['body']);
    }

    #[Test]
    public function parse_returns_empty_for_empty_csv(): void
    {
        $importer = new CsvContentImporter($this->safeHtmlPolicy);
        $result = $importer->parse('');

        self::assertSame([], $result);
    }

    #[Test]
    public function parse_returns_empty_for_headers_only(): void
    {
        $importer = new CsvContentImporter($this->safeHtmlPolicy);
        $result = $importer->parse("title,slug,body\n");

        self::assertSame([], $result);
    }

    #[Test]
    public function parse_skips_rows_with_mismatched_column_count(): void
    {
        $csv = "title,slug,body\n";
        $csv .= "Only,two\n"; // Missing third column
        $csv .= "Good,row,data\n";

        $importer = new CsvContentImporter($this->safeHtmlPolicy);
        $result = $importer->parse($csv);

        self::assertCount(1, $result);
        self::assertSame('Good', $result[0]['translation']['title']);
    }

    #[Test]
    public function parse_maps_content_fields_correctly(): void
    {
        $csv = "id,content_type,status,author_id,locale,title,slug,path,body\n";
        $csv .= "c-1,article,published,user-1,en,Article Title,article-title,/article,<p>Body</p>\n";

        $importer = new CsvContentImporter($this->safeHtmlPolicy);
        $result = $importer->parse($csv);

        self::assertCount(1, $result);
        self::assertSame('c-1', $result[0]['content']['id']);
        self::assertSame('article', $result[0]['content']['content_type']);
        self::assertSame('published', $result[0]['content']['status']);
        self::assertSame('user-1', $result[0]['content']['author_id']);
        self::assertSame('en', $result[0]['translation']['locale']);
        self::assertSame('Article Title', $result[0]['translation']['title']);
        self::assertSame('article-title', $result[0]['translation']['slug']);
    }
}
