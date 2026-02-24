<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Tools\CsvContentImporter;

#[CoversClass(CsvContentImporter::class)]
final class CsvContentImporterTest extends TestCase
{
    private CsvContentImporter $importer;

    protected function setUp(): void
    {
        $this->importer = new CsvContentImporter();
    }

    #[Test]
    public function parseValidCsvReturnsContentAndTranslation(): void
    {
        $csv = "id,content_type,status,author_id,locale,title,slug,path,body,excerpt,meta_title,meta_description,created_at,published_at\n";
        $csv .= "c1,page,published,user-1,en,Hello,hello,hello,<p>Body</p>,Summary,SEO Title,SEO Desc,2024-01-01T00:00:00+00:00,2024-01-01T00:00:00+00:00\n";

        $result = $this->importer->parse($csv);

        self::assertCount(1, $result);
        self::assertSame('c1', $result[0]['content']['id']);
        self::assertSame('page', $result[0]['content']['content_type']);
        self::assertSame('published', $result[0]['content']['status']);
        self::assertSame('user-1', $result[0]['content']['author_id']);
        self::assertSame('en', $result[0]['translation']['locale']);
        self::assertSame('Hello', $result[0]['translation']['title']);
        self::assertSame('hello', $result[0]['translation']['slug']);
        self::assertSame('<p>Body</p>', $result[0]['translation']['body']);
        self::assertSame('Summary', $result[0]['translation']['excerpt']);
        self::assertSame('SEO Title', $result[0]['translation']['meta_title']);
    }

    #[Test]
    public function parseEmptyStringReturnsEmptyArray(): void
    {
        self::assertSame([], $this->importer->parse(''));
    }

    #[Test]
    public function parseHeaderOnlyReturnsEmptyArray(): void
    {
        $csv = "id,content_type,status,author_id,locale,title,slug,path,body\n";
        self::assertSame([], $this->importer->parse($csv));
    }

    #[Test]
    public function parseMultipleRowsReturnsMultipleItems(): void
    {
        $csv = "id,content_type,status,author_id,locale,title,slug,path,body\n";
        $csv .= "c1,page,draft,user-1,en,Title 1,t1,t1,Body 1\n";
        $csv .= "c2,article,published,user-2,fr,Title 2,t2,t2,Body 2\n";

        $result = $this->importer->parse($csv);

        self::assertCount(2, $result);
        self::assertSame('c1', $result[0]['content']['id']);
        self::assertSame('c2', $result[1]['content']['id']);
        self::assertSame('fr', $result[1]['translation']['locale']);
    }

    #[Test]
    public function parseSkipsRowsWithMismatchedColumnCount(): void
    {
        $csv = "id,content_type,status,author_id,locale,title,slug,path,body\n";
        $csv .= "c1,page\n"; // Only 2 columns instead of 9
        $csv .= "c2,page,draft,user-1,en,Valid,valid,valid,Body\n";

        $result = $this->importer->parse($csv);

        self::assertCount(1, $result);
        self::assertSame('c2', $result[0]['content']['id']);
    }

    #[Test]
    public function parseDefaultsForMissingFields(): void
    {
        $csv = "title,slug\n";
        $csv .= "Hello,hello\n";

        $result = $this->importer->parse($csv);

        self::assertCount(1, $result);
        // Missing fields get defaults
        self::assertNull($result[0]['content']['id']);
        self::assertSame('page', $result[0]['content']['content_type']);
        self::assertSame('draft', $result[0]['content']['status']);
        self::assertSame('system', $result[0]['content']['author_id']);
        self::assertSame('en', $result[0]['translation']['locale']);
    }

    #[Test]
    public function parseHandlesEmptyOptionalFields(): void
    {
        $csv = "id,content_type,status,author_id,locale,title,slug,path,body,excerpt,meta_title,meta_description,created_at,published_at\n";
        $csv .= "c1,page,draft,user-1,en,Hello,hello,hello,Body,,,,,\n";

        $result = $this->importer->parse($csv);

        self::assertCount(1, $result);
        self::assertNull($result[0]['translation']['excerpt']);
        self::assertNull($result[0]['translation']['meta_title']);
        self::assertNull($result[0]['translation']['meta_description']);
        self::assertNull($result[0]['content']['created_at']);
        self::assertNull($result[0]['content']['published_at']);
    }

    #[Test]
    public function parseHandlesQuotedCsvFields(): void
    {
        $csv = "id,title,slug,body\n";
        $csv .= "c1,\"Hello, World\",hello,\"<p>Body with \"\"quotes\"\"</p>\"\n";

        $result = $this->importer->parse($csv);

        self::assertCount(1, $result);
        self::assertSame('Hello, World', $result[0]['translation']['title']);
        self::assertSame('<p>Body with "quotes"</p>', $result[0]['translation']['body']);
    }
}
