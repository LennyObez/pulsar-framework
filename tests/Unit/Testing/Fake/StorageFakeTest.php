<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Fake;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\StorageException;
use Pulsar\Testing\Fake\StorageFake;

#[CoversClass(StorageFake::class)]
final class StorageFakeTest extends TestCase
{
    private StorageFake $fake;

    protected function setUp(): void
    {
        $this->fake = new StorageFake();
    }

    #[Test]
    public function put_and_get_stores_content(): void
    {
        $this->fake->put('file.txt', 'Hello, World!');

        self::assertSame('Hello, World!', $this->fake->get('file.txt'));
    }

    #[Test]
    public function get_throws_when_file_missing(): void
    {
        $this->expectException(StorageException::class);

        $this->fake->get('missing.txt');
    }

    #[Test]
    public function exists_returns_false_for_missing(): void
    {
        self::assertFalse($this->fake->exists('missing.txt'));
    }

    #[Test]
    public function exists_returns_true_for_existing(): void
    {
        $this->fake->put('file.txt', 'content');

        self::assertTrue($this->fake->exists('file.txt'));
    }

    #[Test]
    public function delete_removes_file(): void
    {
        $this->fake->put('file.txt', 'content');

        $this->fake->delete('file.txt');

        self::assertFalse($this->fake->exists('file.txt'));
    }

    #[Test]
    public function delete_throws_when_file_missing(): void
    {
        $this->expectException(StorageException::class);

        $this->fake->delete('missing.txt');
    }

    #[Test]
    public function list_returns_all_files(): void
    {
        $this->fake->put('a.txt', 'a');
        $this->fake->put('b.txt', 'b');

        $objects = $this->fake->list();

        self::assertCount(2, $objects);
    }

    #[Test]
    public function list_filters_by_prefix(): void
    {
        $this->fake->put('docs/readme.md', 'readme');
        $this->fake->put('docs/guide.md', 'guide');
        $this->fake->put('images/logo.png', 'logo');

        $objects = $this->fake->list('docs/');

        self::assertCount(2, $objects);
    }

    #[Test]
    public function temporary_url_returns_url_for_existing_file(): void
    {
        $this->fake->put('file.txt', 'content');

        $url = $this->fake->temporaryUrl('file.txt', 600);

        self::assertNotNull($url);
        self::assertStringContainsString('file.txt', $url);
    }

    #[Test]
    public function temporary_url_returns_null_for_missing_file(): void
    {
        self::assertNull($this->fake->temporaryUrl('missing.txt'));
    }

    // ── Assertion Tests ──────────────────────────────────────────────

    #[Test]
    public function assert_exists_passes_when_file_present(): void
    {
        $this->fake->put('file.txt', 'content');

        $this->fake->assertExists('file.txt');
    }

    #[Test]
    public function assert_exists_fails_when_file_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected file [missing.txt] to exist');

        $this->fake->assertExists('missing.txt');
    }

    #[Test]
    public function assert_missing_passes_when_file_absent(): void
    {
        $this->fake->assertMissing('absent.txt');
    }

    #[Test]
    public function assert_content_passes_for_correct_content(): void
    {
        $this->fake->put('file.txt', 'Hello');

        $this->fake->assertContent('file.txt', 'Hello');
    }

    #[Test]
    public function assert_content_fails_for_wrong_content(): void
    {
        $this->fake->put('file.txt', 'Hello');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected file [file.txt] to have specific content');

        $this->fake->assertContent('file.txt', 'World');
    }

    #[Test]
    public function assert_count_passes_for_correct_count(): void
    {
        $this->fake->put('a.txt', 'a');
        $this->fake->put('b.txt', 'b');

        $this->fake->assertCount(2);
    }

    #[Test]
    public function assert_count_with_prefix(): void
    {
        $this->fake->put('docs/a.md', 'a');
        $this->fake->put('docs/b.md', 'b');
        $this->fake->put('images/c.png', 'c');

        $this->fake->assertCount(2, 'docs/');
    }

    #[Test]
    public function assert_operation_passes_when_recorded(): void
    {
        $this->fake->put('file.txt', 'content');

        $this->fake->assertOperation('put', 'file.txt');
    }

    #[Test]
    public function assert_empty_passes_when_storage_is_empty(): void
    {
        $this->fake->assertEmpty();
    }

    #[Test]
    public function operations_records_all_operations(): void
    {
        $this->fake->put('a.txt', 'a');
        $this->fake->get('a.txt');
        $this->fake->delete('a.txt');

        $ops = $this->fake->operations();

        self::assertCount(3, $ops);
        self::assertSame('put', $ops[0]['operation']);
        self::assertSame('get', $ops[1]['operation']);
        self::assertSame('delete', $ops[2]['operation']);
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $this->fake->put('file.txt', 'content');

        $this->fake->reset();

        self::assertSame([], $this->fake->allFiles());
        self::assertSame([], $this->fake->operations());
    }
}
