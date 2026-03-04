<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\HistoryManager;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;

#[CoversClass(HistoryManager::class)]
final class HistoryManagerTest extends TestCase
{
    private const string FILE_PREFIX = 'pulsar_repl_test_history_';

    private string $historyFile;

    protected function setUp(): void
    {
        $this->historyFile = sys_get_temp_dir() . '/' . self::FILE_PREFIX . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // Clean up by clearing content via HistoryManager (no direct unlink needed)
        $manager = new HistoryManager($this->historyFile);
        $manager->clear();
    }

    #[Test]
    public function loadFromNonexistentFileStartsEmpty(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();

        self::assertSame(0, $manager->count());
        self::assertSame([], $manager->all());
    }

    #[Test]
    public function loadFromExistingFilePopulatesEntries(): void
    {
        file_put_contents($this->historyFile, "first\nsecond\nthird\n");

        $manager = new HistoryManager($this->historyFile);
        $manager->load();

        self::assertSame(3, $manager->count());
        self::assertSame(['first', 'second', 'third'], $manager->all());
    }

    #[Test]
    public function loadSkipsEmptyLines(): void
    {
        file_put_contents($this->historyFile, "first\n\n\nsecond\n");

        $manager = new HistoryManager($this->historyFile);
        $manager->load();

        self::assertSame(['first', 'second'], $manager->all());
    }

    #[Test]
    public function addPersistsToFile(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('echo "hello"');

        self::assertSame(1, $manager->count());
        self::assertFileExists($this->historyFile);

        $contents = file_get_contents($this->historyFile);
        self::assertSame("echo \"hello\"\n", $contents);
    }

    #[Test]
    public function addDeduplicatesConsecutiveEntries(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('$x = 1');
        $manager->add('$x = 1');
        $manager->add('$x = 1');

        self::assertSame(1, $manager->count());
        self::assertSame(['$x = 1'], $manager->all());
    }

    #[Test]
    public function addAllowsNonConsecutiveDuplicates(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('first');
        $manager->add('second');
        $manager->add('first');

        self::assertSame(3, $manager->count());
        self::assertSame(['first', 'second', 'first'], $manager->all());
    }

    #[Test]
    public function addIgnoresEmptyCommands(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('');
        $manager->add('   ');

        self::assertSame(0, $manager->count());
    }

    #[Test]
    public function addTrimsWhitespace(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('  echo "hi"  ');

        self::assertSame(['echo "hi"'], $manager->all());
    }

    #[Test]
    public function addEnforcesMaxEntries(): void
    {
        $manager = new HistoryManager($this->historyFile, maxEntries: 3);
        $manager->load();
        $manager->add('one');
        $manager->add('two');
        $manager->add('three');
        $manager->add('four');

        self::assertSame(3, $manager->count());
        self::assertSame(['two', 'three', 'four'], $manager->all());
    }

    #[Test]
    public function previousNavigatesBackward(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('first');
        $manager->add('second');
        $manager->add('third');

        self::assertSame('third', $manager->previous());
        self::assertSame('second', $manager->previous());
        self::assertSame('first', $manager->previous());
        self::assertNull($manager->previous()); // At beginning
    }

    #[Test]
    public function nextNavigatesForward(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('first');
        $manager->add('second');

        (void) $manager->previous(); // → second
        (void) $manager->previous(); // → first

        self::assertSame('second', $manager->next());
        self::assertNull($manager->next()); // At end (current input)
    }

    #[Test]
    public function previousOnEmptyHistoryReturnsNull(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();

        self::assertNull($manager->previous());
    }

    #[Test]
    public function nextOnEmptyHistoryReturnsNull(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();

        self::assertNull($manager->next());
    }

    #[Test]
    public function searchFindsMatchingEntries(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('$user = getUser()');
        $manager->add('$router->get("/api")');
        $manager->add('$user->getName()');

        $results = $manager->search('user');

        self::assertCount(2, $results);
        // Most recent first
        self::assertSame('$user->getName()', $results[0]);
        self::assertSame('$user = getUser()', $results[1]);
    }

    #[Test]
    public function searchIsCaseInsensitive(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('$Router = new Router()');

        $results = $manager->search('router');

        self::assertCount(1, $results);
    }

    #[Test]
    public function searchWithEmptyQueryReturnsEmpty(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('something');

        self::assertSame([], $manager->search(''));
    }

    #[Test]
    public function searchWithNoMatchesReturnsEmpty(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('$x = 1');

        self::assertSame([], $manager->search('nonexistent'));
    }

    #[Test]
    public function clearRemovesAllEntriesAndFile(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('entry one');
        $manager->add('entry two');

        $manager->clear();

        self::assertSame(0, $manager->count());
        self::assertSame([], $manager->all());

        // File should exist but be empty
        $contents = file_get_contents($this->historyFile);
        self::assertSame('', $contents);
    }

    #[Test]
    public function resetCursorMovesToEnd(): void
    {
        $manager = new HistoryManager($this->historyFile);
        $manager->load();
        $manager->add('first');
        $manager->add('second');

        (void) $manager->previous(); // → second
        (void) $manager->previous(); // → first

        $manager->resetCursor();

        // After reset, previous should give the last entry again
        self::assertSame('second', $manager->previous());
    }

    #[Test]
    public function getHistoryFileReturnsConfiguredPath(): void
    {
        $manager = new HistoryManager('/tmp/custom_history');

        self::assertSame('/tmp/custom_history', $manager->getHistoryFile());
    }

    #[Test]
    public function loadEnforcesMaxEntries(): void
    {
        file_put_contents($this->historyFile, "a\nb\nc\nd\ne\n");

        $manager = new HistoryManager($this->historyFile, maxEntries: 3);
        $manager->load();

        self::assertSame(3, $manager->count());
        self::assertSame(['c', 'd', 'e'], $manager->all());
    }
}
