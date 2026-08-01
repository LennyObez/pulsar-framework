<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Hygiene;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Hygiene\ErrorStateResetter;
use Throwable;

use function error_get_last;
use function ob_end_clean;
use function ob_get_level;
use function ob_start;
use function set_error_handler;
use function set_exception_handler;
use function trigger_error;

use const E_USER_NOTICE;

#[CoversClass(ErrorStateResetter::class)]
final class ErrorStateResetterTest extends TestCase
{
    private ErrorStateResetter $resetter;
    private int $obLevelBefore;

    /** @var callable(int, string, string, int): bool|null */
    private mixed $savedErrorHandler;

    /** @var callable(Throwable): void|null */
    private mixed $savedExceptionHandler;

    protected function setUp(): void
    {
        $this->resetter = new ErrorStateResetter();
        $this->obLevelBefore = ob_get_level();

        // Capture current handlers so we can restore them after the resetter nukes them
        $this->savedErrorHandler = set_error_handler(static fn(): bool => false);
        restore_error_handler();

        $this->savedExceptionHandler = set_exception_handler(static fn(): null => null);
        restore_exception_handler();
    }

    protected function tearDown(): void
    {
        // Restore PHPUnit's output buffer level
        while (ob_get_level() < $this->obLevelBefore) {
            ob_start();
        }

        // Restore PHPUnit's error/exception handlers that the resetter removed
        if ($this->savedErrorHandler !== null) {
            set_error_handler($this->savedErrorHandler);
        }

        if ($this->savedExceptionHandler !== null) {
            set_exception_handler($this->savedExceptionHandler);
        }
    }

    #[Test]
    public function it_clears_last_error(): void
    {
        // Trigger an error to populate error_get_last()
        @trigger_error('test error', E_USER_NOTICE);
        self::assertNotNull(error_get_last());

        $this->resetter->reset();

        self::assertNull(error_get_last());
    }

    #[Test]
    public function it_clears_output_buffers(): void
    {
        // Add extra buffers above PHPUnit's level
        ob_start();
        ob_start();
        self::assertSame($this->obLevelBefore + 2, ob_get_level());

        $this->resetter->reset();

        // Reset unwinds to the level the host held when the resetter was built,
        // which is what "between requests" means for a worker: the request's
        // buffers go, the host's stay.
        self::assertSame($this->obLevelBefore, ob_get_level());
    }

    #[Test]
    public function it_is_safe_to_call_multiple_times(): void
    {
        @trigger_error('test error', E_USER_NOTICE);
        ob_start();

        $this->resetter->reset();
        $this->resetter->reset();

        self::assertNull(error_get_last());
        self::assertSame($this->obLevelBefore, ob_get_level());
    }

    /**
     * A worker started with `output_buffering` on holds a buffer before it serves
     * anything. Unwinding past it would leave later requests unbuffered against
     * the operator's php.ini.
     */
    #[Test]
    public function it_preserves_the_buffer_the_host_already_held(): void
    {
        ob_start();
        $hostLevel = ob_get_level();

        // Built at the composition root, i.e. now — after the host's buffer exists.
        $resetter = new ErrorStateResetter();

        ob_start();
        ob_start();
        $resetter->reset();

        self::assertSame($hostLevel, ob_get_level());

        ob_end_clean();
    }

    #[Test]
    public function it_honours_an_explicit_baseline(): void
    {
        $resetter = new ErrorStateResetter(baseBufferLevel: $this->obLevelBefore);

        ob_start();
        ob_start();
        $resetter->reset();

        self::assertSame($this->obLevelBefore, ob_get_level());
    }
}
