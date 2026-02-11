<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Fake;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Fake\CacheFake;

#[CoversClass(CacheFake::class)]
final class CacheFakeTest extends TestCase
{
    private CacheFake $fake;

    protected function setUp(): void
    {
        $this->fake = new CacheFake();
    }

    #[Test]
    public function get_returns_null_for_missing_key(): void
    {
        self::assertNull($this->fake->get('missing'));
    }

    #[Test]
    public function set_and_get_stores_value(): void
    {
        $this->fake->set('key', 'value', null);

        self::assertSame('value', $this->fake->get('key'));
    }

    #[Test]
    public function set_with_zero_ttl_deletes_key(): void
    {
        $this->fake->set('key', 'value', null);
        $this->fake->set('key', 'new', 0);

        self::assertNull($this->fake->get('key'));
    }

    #[Test]
    public function set_with_negative_ttl_deletes_key(): void
    {
        $this->fake->set('key', 'value', null);
        $this->fake->set('key', 'new', -1);

        self::assertNull($this->fake->get('key'));
    }

    #[Test]
    public function get_multiple_returns_values(): void
    {
        $this->fake->set('a', '1', null);
        $this->fake->set('b', '2', null);

        $result = $this->fake->getMultiple(['a', 'b', 'c']);

        self::assertSame('1', $result['a']);
        self::assertSame('2', $result['b']);
        self::assertNull($result['c']);
    }

    #[Test]
    public function set_multiple_stores_values(): void
    {
        $this->fake->setMultiple(['a' => '1', 'b' => '2'], null);

        self::assertSame('1', $this->fake->get('a'));
        self::assertSame('2', $this->fake->get('b'));
    }

    #[Test]
    public function delete_removes_key(): void
    {
        $this->fake->set('key', 'value', null);

        $this->fake->delete('key');

        self::assertNull($this->fake->get('key'));
    }

    #[Test]
    public function delete_multiple_removes_keys(): void
    {
        $this->fake->set('a', '1', null);
        $this->fake->set('b', '2', null);

        $this->fake->deleteMultiple(['a', 'b']);

        self::assertNull($this->fake->get('a'));
        self::assertNull($this->fake->get('b'));
    }

    #[Test]
    public function has_returns_false_for_missing(): void
    {
        self::assertFalse($this->fake->has('missing'));
    }

    #[Test]
    public function has_returns_true_for_existing(): void
    {
        $this->fake->set('key', 'value', null);

        self::assertTrue($this->fake->has('key'));
    }

    #[Test]
    public function clear_removes_all_keys(): void
    {
        $this->fake->set('a', '1', null);
        $this->fake->set('b', '2', null);

        $this->fake->clear();

        self::assertNull($this->fake->get('a'));
        self::assertNull($this->fake->get('b'));
    }

    #[Test]
    public function increment_creates_counter(): void
    {
        self::assertSame(1, $this->fake->increment('counter'));
        self::assertSame(2, $this->fake->increment('counter'));
    }

    #[Test]
    public function increment_with_step(): void
    {
        self::assertSame(5, $this->fake->increment('counter', 5));
    }

    #[Test]
    public function decrement_decreases_counter(): void
    {
        $this->fake->increment('counter', 10);

        self::assertSame(7, $this->fake->decrement('counter', 3));
    }

    #[Test]
    public function capabilities_reports_full_support(): void
    {
        $caps = $this->fake->capabilities();

        self::assertTrue($caps->supportsBinary);
        self::assertTrue($caps->supportsAtomicIncrement);
    }

    #[Test]
    public function name_returns_fake(): void
    {
        self::assertSame('fake', $this->fake->name());
    }

    // ── Assertion Tests ──────────────────────────────────────────────

    #[Test]
    public function assert_has_passes_when_key_exists(): void
    {
        $this->fake->set('key', 'value', null);

        $this->fake->assertHas('key');
    }

    #[Test]
    public function assert_has_fails_when_key_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected cache to contain key [missing]');

        $this->fake->assertHas('missing');
    }

    #[Test]
    public function assert_missing_passes_when_key_absent(): void
    {
        $this->fake->assertMissing('absent');
    }

    #[Test]
    public function assert_value_passes_for_correct_value(): void
    {
        $this->fake->set('key', 'hello', null);

        $this->fake->assertValue('key', 'hello');
    }

    #[Test]
    public function assert_value_fails_for_wrong_value(): void
    {
        $this->fake->set('key', 'hello', null);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected cache key [key] to have value');

        $this->fake->assertValue('key', 'world');
    }

    #[Test]
    public function assert_operation_passes_when_recorded(): void
    {
        $this->fake->set('key', 'val', null);

        $this->fake->assertOperation('set', 'key');
    }

    #[Test]
    public function assert_empty_passes_when_store_is_empty(): void
    {
        $this->fake->assertEmpty();
    }

    #[Test]
    public function assert_empty_fails_when_store_is_not_empty(): void
    {
        $this->fake->set('key', 'value', null);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected cache to be empty');

        $this->fake->assertEmpty();
    }

    #[Test]
    public function operations_records_all_operations(): void
    {
        $this->fake->set('a', '1', null);
        $this->fake->get('a');
        $this->fake->delete('a');

        $ops = $this->fake->operations();

        self::assertCount(3, $ops);
        self::assertSame('set', $ops[0]['operation']);
        self::assertSame('get', $ops[1]['operation']);
        self::assertSame('delete', $ops[2]['operation']);
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $this->fake->set('key', 'value', null);

        $this->fake->reset();

        self::assertSame([], $this->fake->all());
        self::assertSame([], $this->fake->operations());
    }
}
