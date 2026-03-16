<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Dev;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Generate and start the Docker development environment.
 *
 * Generates Dockerfile, docker-compose.yml, and .dockerignore,
 * then prints the commands needed to build and start the environment.
 */
final class DevStartCommand extends Command
{
    public function __construct(
        private readonly DevConfig $config,
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'dev:start';
        $this->description = 'Generate Docker development environment files';

        $this->addOption('database', 'Database driver (pgsql, mysql, sqlite)');
        $this->addOption('no-redis', 'Disable Redis service');
        $this->addOption('no-mailpit', 'Disable Mailpit email testing service');
        $this->addOption('port', 'Application port (default: 8080)');
        $this->addOption('rebuild', 'Force regeneration of Dockerfile');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->resolveConfig($input);

        $output->info('Generating Docker development environment...');

        // Generate Dockerfile
        $dockerfilePath = $this->projectRoot . '/Dockerfile';

        if (!file_exists($dockerfilePath) || $input->hasOption('rebuild')) {
            $dockerfileGenerator = new DockerfileGenerator($config);
            $dockerfileContent = $dockerfileGenerator->generate();

            if (file_put_contents($dockerfilePath, $dockerfileContent) === false) {
                $output->error('Failed to write Dockerfile');

                return ExitCode::Error->value;
            }

            $output->info('Generated Dockerfile');
        }

        // Generate docker-compose.yml
        $composePath = $this->projectRoot . '/docker-compose.yml';
        $composeGenerator = new ComposeFileGenerator($config);
        $composeContent = $composeGenerator->generate();

        if (file_put_contents($composePath, $composeContent) === false) {
            $output->error('Failed to write docker-compose.yml');

            return ExitCode::Error->value;
        }

        $output->info('Generated docker-compose.yml');

        // Ensure database directory for SQLite
        if ($config->database === 'sqlite') {
            $dbDir = $this->projectRoot . '/database';

            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0o750, true);
            }
        }

        // Generate .dockerignore if missing
        $dockerignorePath = $this->projectRoot . '/.dockerignore';

        if (!file_exists($dockerignorePath)) {
            $this->writeDockerignore($dockerignorePath);
            $output->info('Generated .dockerignore');
        }

        // Print instructions
        $output->newLine();
        $output->success('Docker environment files generated successfully.');
        $output->newLine();
        $output->writeln('To start the development environment, run:');
        $output->newLine();
        $output->writeln(sprintf('  docker compose -p %s up --build -d', $config->projectName));
        $output->newLine();
        $output->writeln(sprintf('Application:  http://localhost:%d', $config->appPort));

        if ($config->mailpit) {
            $output->writeln(sprintf('Mailpit UI:   http://localhost:%d', $config->mailpitWebPort));
        }

        if ($config->requiresDatabaseContainer()) {
            $dbService = $config->database === 'mysql' ? 'mysql' : 'postgres';
            $output->writeln(sprintf('Database:     %s on port %d', $dbService, $config->dbPort));
        }

        if ($config->redis) {
            $output->writeln(sprintf('Redis:        localhost:%d', $config->redisPort));
        }

        $output->newLine();
        $output->writeln('Other commands:');
        $output->writeln(sprintf('  docker compose -p %s down      # Stop environment', $config->projectName));
        $output->writeln(sprintf('  docker compose -p %s restart   # Restart services', $config->projectName));
        $output->writeln(sprintf('  docker compose -p %s ps        # Show status', $config->projectName));

        return ExitCode::Success->value;
    }

    private function resolveConfig(InputInterface $input): DevConfig
    {
        /** @var string|null $database */
        $database = $input->getOption('database');

        /** @var string|null $port */
        $port = $input->getOption('port');

        return new DevConfig(
            phpVersion: $this->config->phpVersion,
            database: $database ?? $this->config->database,
            redis: !$input->hasOption('no-redis') && $this->config->redis,
            mailpit: !$input->hasOption('no-mailpit') && $this->config->mailpit,
            serverDriver: $this->config->serverDriver,
            appPort: $port !== null ? (int) $port : $this->config->appPort,
            dbPort: $this->config->dbPort,
            redisPort: $this->config->redisPort,
            mailpitSmtpPort: $this->config->mailpitSmtpPort,
            mailpitWebPort: $this->config->mailpitWebPort,
            memoryLimit: $this->config->memoryLimit,
            xdebug: $this->config->xdebug,
            phpExtensions: $this->config->phpExtensions,
            projectName: $this->config->projectName,
        );
    }

    private function writeDockerignore(string $path): void
    {
        $content = <<<'DOCKERIGNORE'
            .git
            .github
            node_modules
            vendor
            .env
            .env.*
            *.log
            docker-compose.yml
            docker-compose.override.yml
            Dockerfile
            .dockerignore
            tests/
            docs/
            tools/
            .phpunit.cache
            .php-cs-fixer.cache
            DOCKERIGNORE;

        file_put_contents($path, $content);
    }
}
