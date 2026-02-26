<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Hygiene;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Hygiene\ErrorStateResetter;
use Pulsar\Runtime\Hygiene\HygieneProfileInterface;
use Pulsar\Runtime\Hygiene\PersistentRuntimeHygiene;
use Pulsar\Runtime\Hygiene\SuperglobalResetter;
use Throwable;

use function error_get_last;
use function ob_get_level;
use function ob_start;
use function set_error_handler;
use function set_exception_handler;
use function trigger_error;

use const E_USER_NOTICE;

#[CoversClass(PersistentRuntimeHygiene::class)]
#[CoversClass(SuperglobalResetter::class)]
#[CoversClass(ErrorStateResetter::class)]
final class PersistentRuntimeHygieneTest extends TestCase
{
    private PersistentRuntimeHygiene $hygiene;

    /** @var array<string, mixed> */
    private array $originalServer;
    private int $obLevelBefore;

    private bool $hygieneApplied = false;

    /** @var callable(int, string, string, int): bool|null */
    private mixed $savedErrorHandler;

    /** @var callable(Throwable): void|null */
    private mixed $savedExceptionHandler;

    protected function setUp(): void
    {
        $this->hygiene = new PersistentRuntimeHygiene();

        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $this->originalServer = $server;
        $this->obLevelBefore = ob_get_level();
        $this->hygieneApplied = false;

        // Capture current handlers so we can restore them after apply() nukes them
        $this->savedErrorHandler = set_error_handler(static fn(): bool => false);
        restore_error_handler();

        $this->savedExceptionHandler = set_exception_handler(static fn(): null => null);
        restore_exception_handler();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];

        // Restore PHPUnit's output buffer level
        while (ob_get_level() < $this->obLevelBefore) {
            ob_start();
        }

        // Only restore handlers if apply() was called (which removes them)
        if ($this->hygieneApplied) {
            if ($this->savedErrorHandler !== null) {
                set_error_handler($this->savedErrorHandler);
            }

            if ($this->savedExceptionHandler !== null) {
                set_exception_handler($this->savedExceptionHandler);
            }
        }
    }

    private function applyHygiene(): void
    {
        $this->hygieneApplied = true;
        $this->hygiene->apply();
    }

    #[Test]
    public function it_resets_superglobals(): void
    {
        $_GET = ['page' => '1'];
        $_POST = ['data' => 'value'];
        $_COOKIE = ['sid' => 'abc'];

        $this->applyHygiene();

        self::assertSame([], $_GET);
        self::assertSame([], $_POST);
        self::assertSame([], $_COOKIE);
    }

    #[Test]
    public function it_clears_error_state(): void
    {
        @trigger_error('stale error', E_USER_NOTICE);
        self::assertNotNull(error_get_last());

        $this->applyHygiene();

        self::assertNull(error_get_last());
    }

    #[Test]
    public function it_clears_output_buffers(): void
    {
        ob_start();

        $this->applyHygiene();

        self::assertSame(0, ob_get_level());
    }

    #[Test]
    public function it_resets_all_dirty_state_in_single_apply(): void
    {
        // Set up maximally dirty state
        $_GET = ['q' => 'search'];
        $_POST = ['action' => 'submit'];
        $_COOKIE = ['token' => 'xyz'];
        $_FILES = ['file' => ['name' => 'doc.pdf']];
        $_REQUEST = ['merged' => 'data'];
        $_SERVER['REQUEST_URI'] = '/dirty/path';
        $_SERVER['HTTP_HOST'] = 'dirty.example.com';

        @trigger_error('dirty error', E_USER_NOTICE);
        ob_start();

        // Single apply should clean everything
        $this->applyHygiene();

        self::assertSame([], $_GET);
        self::assertSame([], $_POST);
        self::assertSame([], $_COOKIE);
        self::assertSame([], $_FILES);
        self::assertSame([], $_REQUEST);
        self::assertArrayNotHasKey('REQUEST_URI', $_SERVER);
        self::assertArrayNotHasKey('HTTP_HOST', $_SERVER);
        self::assertNull(error_get_last());
        self::assertSame(0, ob_get_level());
    }

    #[Test]
    public function it_implements_hygiene_profile_interface(): void
    {
        self::assertInstanceOf(
            HygieneProfileInterface::class,
            $this->hygiene,
        );
    }
}
