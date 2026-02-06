<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Hygiene;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Hygiene\SuperglobalResetter;

#[CoversClass(SuperglobalResetter::class)]
final class SuperglobalResetterTest extends TestCase
{
    private SuperglobalResetter $resetter;

    /** @var array<string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        $this->resetter = new SuperglobalResetter();

        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $this->originalServer = $server;
    }

    protected function tearDown(): void
    {
        // Restore original state so test runner is not affected
        $_SERVER = $this->originalServer;
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];
    }

    #[Test]
    public function it_clears_get_superglobal(): void
    {
        $_GET = ['page' => '1', 'sort' => 'name'];

        $this->resetter->reset();

        self::assertSame([], $_GET);
    }

    #[Test]
    public function it_clears_post_superglobal(): void
    {
        $_POST = ['username' => 'admin', 'password' => 'secret'];

        $this->resetter->reset();

        self::assertSame([], $_POST);
    }

    #[Test]
    public function it_clears_cookie_superglobal(): void
    {
        $_COOKIE = ['session_id' => 'abc123'];

        $this->resetter->reset();

        self::assertSame([], $_COOKIE);
    }

    #[Test]
    public function it_clears_files_superglobal(): void
    {
        $_FILES = ['upload' => ['name' => 'test.txt', 'size' => 100]];

        $this->resetter->reset();

        self::assertSame([], $_FILES);
    }

    #[Test]
    public function it_clears_request_superglobal(): void
    {
        $_REQUEST = ['key' => 'value'];

        $this->resetter->reset();

        self::assertSame([], $_REQUEST);
    }

    #[Test]
    public function it_preserves_process_level_server_keys(): void
    {
        $_SERVER = [
            'SERVER_SOFTWARE' => 'Pulsar/1.0',
            'SERVER_NAME' => 'localhost',
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '8080',
            'DOCUMENT_ROOT' => '/var/www',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'PHP_SELF' => '/index.php',
            'argv' => [],
            'argc' => 0,
            'REQUEST_URI' => '/api/users',
            'HTTP_HOST' => 'example.com',
            'QUERY_STRING' => 'page=1',
        ];

        $this->resetter->reset();

        self::assertSame('Pulsar/1.0', $_SERVER['SERVER_SOFTWARE']);
        self::assertSame('localhost', $_SERVER['SERVER_NAME']);
        self::assertSame('127.0.0.1', $_SERVER['SERVER_ADDR']);
        self::assertSame('8080', $_SERVER['SERVER_PORT']);
        self::assertSame('/var/www', $_SERVER['DOCUMENT_ROOT']);
        self::assertSame('/var/www/index.php', $_SERVER['SCRIPT_FILENAME']);
        self::assertSame('/index.php', $_SERVER['PHP_SELF']);
        self::assertSame([], $_SERVER['argv']);
        self::assertSame(0, $_SERVER['argc']);
    }

    #[Test]
    public function it_clears_request_level_server_keys(): void
    {
        $_SERVER = [
            'SERVER_SOFTWARE' => 'Pulsar/1.0',
            'REQUEST_URI' => '/api/users',
            'HTTP_HOST' => 'example.com',
            'QUERY_STRING' => 'page=1',
            'REMOTE_ADDR' => '10.0.0.1',
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->resetter->reset();

        self::assertArrayNotHasKey('REQUEST_URI', $_SERVER);
        self::assertArrayNotHasKey('HTTP_HOST', $_SERVER);
        self::assertArrayNotHasKey('QUERY_STRING', $_SERVER);
        self::assertArrayNotHasKey('REMOTE_ADDR', $_SERVER);
        self::assertArrayNotHasKey('REQUEST_METHOD', $_SERVER);
        self::assertArrayNotHasKey('CONTENT_TYPE', $_SERVER);
    }

    #[Test]
    public function it_does_not_touch_session_when_inactive(): void
    {
        // session_status() will return PHP_SESSION_NONE in CLI
        // This should NOT cause errors or warnings
        $this->expectNotToPerformAssertions();
        $this->resetter->reset();
    }

    #[Test]
    public function it_clears_all_request_superglobals_at_once(): void
    {
        $_GET = ['a' => '1'];
        $_POST = ['b' => '2'];
        $_COOKIE = ['c' => '3'];
        $_FILES = ['d' => ['name' => 'f.txt']];
        $_REQUEST = ['e' => '5'];

        $this->resetter->reset();

        self::assertSame([], $_GET);
        self::assertSame([], $_POST);
        self::assertSame([], $_COOKIE);
        self::assertSame([], $_FILES);
        self::assertSame([], $_REQUEST);
    }
}
