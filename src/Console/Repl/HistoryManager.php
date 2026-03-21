<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;

use function array_reverse;
use function array_slice;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function str_contains;
use function strtolower;
use function trim;

use const FILE_APPEND;
use const LOCK_EX;

/**
 * Manages persistent REPL command history.
 *
 * Stores history in a user-configurable file (default: ~/.pulsar_repl_history).
 * Supports search, navigation, deduplication, and maximum entry limits.
 * @api
 */
#[Api(since: '1.0.0')]
final class HistoryManager
{
    /** @var list<string> */
    private array $entries = [];

    private int $cursor = -1;

    public function __construct(
        private readonly string $historyFile,
        private readonly int $maxEntries = 1000,
    ) {}

    /**
     * Load history entries from the persistent file.
     *
     * Reads the file line by line, trimming whitespace. Empty lines are skipped.
     * If the file does not exist, starts with an empty history.
     */
    public function load(): void
    {
        if (!file_exists($this->historyFile)) {
            $this->entries = [];

            return;
        }

        $contents = file_get_contents($this->historyFile);

        if ($contents === false) {
            $this->entries = [];

            return;
        }

        $lines = explode("\n", $contents);
        $this->entries = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                $this->entries[] = $trimmed;
            }
        }

        // Enforce max entries
        if (count($this->entries) > $this->maxEntries) {
            $this->entries = array_slice($this->entries, -$this->maxEntries);
        }

        $this->cursor = count($this->entries);
    }

    /**
     * Add a command to the history.
     *
     * Deduplicates consecutive identical entries. Appends to the persistent
     * file immediately (append + lock for concurrency safety).
     */
    public function add(string $command): void
    {
        $command = trim($command);

        if ($command === '') {
            return;
        }

        // Deduplicate consecutive entries
        if ($this->entries !== [] && $this->entries[count($this->entries) - 1] === $command) {
            $this->cursor = count($this->entries);

            return;
        }

        $this->entries[] = $command;

        // Enforce max entries
        if (count($this->entries) > $this->maxEntries) {
            $this->entries = array_slice($this->entries, -$this->maxEntries);
            $this->save();
        } else {
            $this->appendToFile($command);
        }

        $this->cursor = count($this->entries);
    }

    /**
     * Navigate backward through history (older entries).
     *
     * Returns the previous entry, or null if at the beginning.
     */
    #[NoDiscard]
    public function previous(): ?string
    {
        if ($this->entries === [] || $this->cursor <= 0) {
            return null;
        }

        $this->cursor--;

        return $this->entries[$this->cursor];
    }

    /**
     * Navigate forward through history (newer entries).
     *
     * Returns the next entry, or null if at the end (current input).
     */
    #[NoDiscard]
    public function next(): ?string
    {
        if ($this->cursor >= count($this->entries) - 1) {
            $this->cursor = count($this->entries);

            return null;
        }

        $this->cursor++;

        return $this->entries[$this->cursor];
    }

    /**
     * Search history entries matching a query string (case-insensitive).
     *
     * Returns matches in reverse chronological order (most recent first).
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function search(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $lowerQuery = strtolower($query);
        $matches = [];

        foreach (array_reverse($this->entries) as $entry) {
            if (str_contains(strtolower($entry), $lowerQuery)) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    /**
     * Get all history entries.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * Get the number of history entries.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Clear all history entries and the persistent file.
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->cursor = -1;

        if (file_exists($this->historyFile)) {
            file_put_contents($this->historyFile, '', LOCK_EX);
        }
    }

    /**
     * Reset the navigation cursor to the end of history.
     */
    public function resetCursor(): void
    {
        $this->cursor = count($this->entries);
    }

    /**
     * Get the path to the history file.
     */
    #[NoDiscard]
    public function getHistoryFile(): string
    {
        return $this->historyFile;
    }

    /**
     * Save the full history to file (used when truncating).
     */
    private function save(): void
    {
        $dir = dirname($this->historyFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        file_put_contents(
            $this->historyFile,
            implode("\n", $this->entries) . "\n",
            LOCK_EX,
        );
    }

    /**
     * Append a single entry to the history file.
     */
    private function appendToFile(string $command): void
    {
        $dir = dirname($this->historyFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        file_put_contents(
            $this->historyFile,
            $command . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }
}
