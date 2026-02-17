<?php

declare(strict_types=1);

namespace Pulsar\Database\Seeder;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;
use Throwable;

use function array_map;
use function array_values;
use function class_exists;
use function is_dir;
use function is_file;
use function pathinfo;
use function sprintf;
use function usort;

/**
 * Discovers and executes database seeders.
 *
 * Seeders are PHP files in the configured seeder directory that return
 * an instance of SeederInterface (typically anonymous classes).
 */
#[Api(since: '1.0.0')]
final readonly class SeederRunner implements SeederRunnerInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $seederPath,
    ) {}

    /**
     * Run all discovered seeders.
     *
     * @return list<string> Identifiers of executed seeders
     * @throws DatabaseException
     */
    public function runAll(): array
    {
        $seeders = $this->discover();

        if ($seeders === []) {
            return [];
        }

        $executed = [];

        foreach ($seeders as $entry) {
            $seeder = $this->loadSeeder($entry->path);

            try {
                $seeder->run($this->connection);
            } catch (Throwable $e) {
                throw DatabaseException::seederFailed($entry->name, $e);
            }

            $executed[] = $seeder->identifier();
        }

        return $executed;
    }

    /**
     * Run a specific seeder by class name.
     *
     * @param class-string<SeederInterface> $className
     * @throws DatabaseException
     */
    public function runClass(string $className): void
    {
        if (!class_exists($className)) {
            throw DatabaseException::seederNotFound($className);
        }

        /** @var SeederInterface $seeder */
        $seeder = new $className();

        try {
            $seeder->run($this->connection);
        } catch (Throwable $e) {
            throw DatabaseException::seederFailed($className, $e);
        }
    }

    /**
     * Run a specific seeder file by name.
     *
     * @throws DatabaseException
     */
    public function runByName(string $name): void
    {
        $path = $this->seederPath . '/' . $name . '.php';

        if (!is_file($path)) {
            throw DatabaseException::seederNotFound($name);
        }

        $seeder = $this->loadSeeder($path);

        try {
            $seeder->run($this->connection);
        } catch (Throwable $e) {
            throw DatabaseException::seederFailed($name, $e);
        }
    }

    /**
     * Discover all seeder files in the configured path.
     *
     * @return list<SeederEntry>
     */
    public function discover(): array
    {
        if (!is_dir($this->seederPath)) {
            return [];
        }

        $files = glob($this->seederPath . '/*.php');
        if ($files === false) {
            return [];
        }

        $entries = array_values(array_map(
            static function (string $path): SeederEntry {
                $info = pathinfo($path);

                return new SeederEntry(
                    name: $info['filename'],
                    path: $path,
                );
            },
            $files,
        ));

        usort($entries, static fn(SeederEntry $a, SeederEntry $b): int => $a->name <=> $b->name);

        return $entries;
    }

    /**
     * @throws DatabaseException
     */
    private function loadSeeder(string $path): SeederInterface
    {
        /** @var SeederInterface|mixed $result */
        $result = require $path;

        if (!$result instanceof SeederInterface) {
            throw DatabaseException::seederInvalid(
                sprintf('File "%s" must return an instance of SeederInterface', $path),
            );
        }

        return $result;
    }
}
