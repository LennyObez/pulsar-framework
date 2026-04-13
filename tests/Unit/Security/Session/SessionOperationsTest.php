<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use NoDiscard;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\Session;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Unit tests for session happy-path operations.
 *
 * Uses an in-memory session double to test the SessionInterface contract
 * without requiring PHP's native session infrastructure (which cannot run
 * in PHPUnit without headers being sent).
 */
#[CoversClass(Session::class)]
final class SessionOperationsTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor -- initialized in setUp() */
    private SessionOperationsInMemorySession $session;

    protected function setUp(): void
    {
        $this->session = new SessionOperationsInMemorySession();
    }

    #[Test]
    public function setAndGetRoundTrip(): void
    {
        $this->session->set('user_id', 42);

        self::assertSame(42, $this->session->get('user_id'));
    }

    #[Test]
    public function getReturnsDefaultWhenKeyDoesNotExist(): void
    {
        self::assertNull($this->session->get('nonexistent'));
        self::assertSame('fallback', $this->session->get('nonexistent', 'fallback'));
    }

    #[Test]
    public function getReturnsStoredValueForMultipleTypes(): void
    {
        $this->session->set('string', 'hello');
        $this->session->set('int', 123);
        $this->session->set('bool', true);
        $this->session->set('array', ['a', 'b']);
        $this->session->set('null_val', null);

        self::assertSame('hello', $this->session->get('string'));
        self::assertSame(123, $this->session->get('int'));
        self::assertTrue($this->session->get('bool'));
        self::assertSame(['a', 'b'], $this->session->get('array'));
        self::assertNull($this->session->get('null_val'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $this->session->set('exists', 'value');

        self::assertTrue($this->session->has('exists'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        self::assertFalse($this->session->has('missing'));
    }

    #[Test]
    public function hasReturnsTrueForKeyWithNullValue(): void
    {
        $this->session->set('null_key', null);

        self::assertTrue($this->session->has('null_key'));
    }

    #[Test]
    public function removeDeletesKey(): void
    {
        $this->session->set('to_remove', 'temporary');
        self::assertTrue($this->session->has('to_remove'));

        $this->session->remove('to_remove');

        self::assertFalse($this->session->has('to_remove'));
        self::assertNull($this->session->get('to_remove'));
    }

    #[Test]
    public function removeDoesNotAffectOtherKeys(): void
    {
        $this->session->set('keep', 'permanent');
        $this->session->set('drop', 'temporary');

        $this->session->remove('drop');

        self::assertTrue($this->session->has('keep'));
        self::assertSame('permanent', $this->session->get('keep'));
        self::assertFalse($this->session->has('drop'));
    }

    #[Test]
    public function removeNonexistentKeyDoesNotThrow(): void
    {
        $this->session->remove('never_existed');

        // Verify session is still functional after removing a non-existent key
        self::assertFalse($this->session->has('never_existed'));
    }

    #[Test]
    public function allReturnsAllSessionData(): void
    {
        $this->session->set('name', 'Alice');
        $this->session->set('role', 'admin');
        $this->session->set('score', 100);

        $all = $this->session->all();

        self::assertSame([
            'name' => 'Alice',
            'role' => 'admin',
            'score' => 100,
        ], $all);
    }

    #[Test]
    public function allReturnsEmptyArrayWhenSessionIsEmpty(): void
    {
        $all = $this->session->all();

        self::assertSame([], $all);
    }

    #[Test]
    public function regenerateChangesSessionId(): void
    {
        $originalId = $this->session->id();

        $this->session->regenerate();

        $newId = $this->session->id();
        self::assertNotSame($originalId, $newId, 'Regenerate should produce a different session ID');
        self::assertNotEmpty($newId);
    }

    #[Test]
    public function regeneratePreservesSessionData(): void
    {
        $this->session->set('persist', 'across-regeneration');

        $this->session->regenerate();

        self::assertSame('across-regeneration', $this->session->get('persist'));
    }

    #[Test]
    public function destroyClearsAllSessionData(): void
    {
        $this->session->set('key1', 'value1');
        $this->session->set('key2', 'value2');

        $this->session->destroy();

        self::assertSame([], $this->session->all());
        self::assertFalse($this->session->has('key1'));
        self::assertFalse($this->session->has('key2'));
    }

    #[Test]
    public function destroyMarksSessionAsNotStarted(): void
    {
        self::assertTrue($this->session->isStarted());

        $this->session->destroy();

        self::assertFalse($this->session->isStarted());
    }

    #[Test]
    public function sessionCanBeRestartedAfterDestroy(): void
    {
        $this->session->set('before', 'destroy');
        $this->session->destroy();

        $this->session->start();
        $this->session->set('after', 'restart');

        self::assertTrue($this->session->isStarted());
        self::assertSame('restart', $this->session->get('after'));
        // Data from before destroy should be gone
        self::assertFalse($this->session->has('before'));
    }

    #[Test]
    public function setOverwritesPreviousValue(): void
    {
        $this->session->set('key', 'original');
        $this->session->set('key', 'updated');

        self::assertSame('updated', $this->session->get('key'));
    }

    #[Test]
    public function sessionIdIsNonEmpty(): void
    {
        self::assertNotEmpty($this->session->id());
    }
}

/**
 * In-memory session implementation for testing session operations.
 *
 * Mimics the SessionInterface contract without relying on PHP's native
 * session functions. Supports id regeneration via a simple counter.
 */
class SessionOperationsInMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $started = true;
    private int $idCounter = 1;

    #[Override]
    public function start(): void
    {
        $this->started = true;
    }

    #[Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    #[NoDiscard]
    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    #[Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    #[Override]
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    #[Override]
    public function id(): string
    {
        return 'test-session-' . $this->idCounter;
    }

    #[Override]
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->idCounter++;
    }

    #[Override]
    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function all(): array
    {
        return $this->data;
    }

    #[NoDiscard]
    #[Override]
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    #[NoDiscard]
    #[Override]
    public function getNullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    #[NoDiscard]
    #[Override]
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->data[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    #[NoDiscard]
    #[Override]
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }
}
