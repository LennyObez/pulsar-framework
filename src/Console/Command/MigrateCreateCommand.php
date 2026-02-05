<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function date;
use function file_put_contents;
use function is_dir;
use function is_string;
use function mkdir;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Create a new migration file.
 */
final class MigrateCreateCommand extends Command
{
    public function __construct(
        private readonly string $migrationsPath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'migrate:create';
        $this->description = 'Create a new migration file';
        $this->addArgument('name', 'Migration name (e.g., create_users_table)', true);
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);

        if (!is_string($name) || trim($name) === '') {
            $output->errorln('Migration name is required.');
            return ExitCode::Invalid->value;
        }

        $name = $this->toSnakeCase($name);
        $version = date('YmdHis');
        $filename = sprintf('%s_%s.php', $version, $name);

        // Ensure migrations directory exists
        if (!is_dir($this->migrationsPath)) {
            if (!mkdir($this->migrationsPath, 0o755, true)) {
                $output->errorln(sprintf('Failed to create migrations directory: %s', $this->migrationsPath));
                return ExitCode::Error->value;
            }
        }

        $filePath = $this->migrationsPath . DIRECTORY_SEPARATOR . $filename;
        $content = $this->generateContent();

        if (file_put_contents($filePath, $content) === false) {
            $output->errorln(sprintf('Failed to write migration file: %s', $filePath));
            return ExitCode::Error->value;
        }

        $output->success(sprintf('Created migration: %s', $filename));
        $output->writeln(sprintf('  Path: %s', $filePath));

        return ExitCode::Success->value;
    }

    private function toSnakeCase(string $name): string
    {
        // Replace spaces, hyphens, and camelCase with underscores
        $snake = (string) preg_replace('/[^a-z0-9]+/i', '_', $name);
        $snake = (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $snake);

        return strtolower(trim($snake, '_'));
    }

    private function generateContent(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;

            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void
                {
                    // $connection->execute('CREATE TABLE ...');
                }

                public function down(ConnectionInterface $connection): void
                {
                    // $connection->execute('DROP TABLE IF EXISTS ...');
                }
            };
            PHP;
    }
}
