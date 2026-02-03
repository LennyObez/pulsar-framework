<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function dirname;
use function is_string;

use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Initialize a new Pulsar project.
 */
final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Initialize a new Pulsar project')
            ->addArgument('directory', 'Target directory (default: current directory)', false)
            ->addOption('force', 'Overwrite existing files', 'f');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getArgument(0) ?? getcwd();
        $force = $input->hasOption('force');

        if (!is_string($directory)) {
            $output->errorln('Invalid directory argument.');
            return ExitCode::Invalid->value;
        }

        // Resolve to absolute path
        if (!str_starts_with($directory, '/') && !preg_match('/^[A-Za-z]:/', $directory)) {
            $cwd = getcwd();
            if ($cwd === false) {
                $output->errorln('Failed to get current working directory.');
                return ExitCode::Error->value;
            }
            $directory = $cwd . DIRECTORY_SEPARATOR . $directory;
        }

        $output->writeln(sprintf('Initializing Pulsar project in: %s', $directory));
        $output->newLine();

        // Create directory if needed
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0o755, true)) {
                $output->errorln('Failed to create directory.');
                return ExitCode::Error->value;
            }
            $output->writeln('  Created directory');
        }

        // Create project structure
        $structure = [
            'app' => [
                'Controllers' => [],
                'Middleware' => [],
                'Services' => [],
            ],
            'config' => [],
            'public' => [],
            'extensions' => [],
            'tests' => [
                'Unit' => [],
                'Integration' => [],
            ],
        ];

        $this->createStructure($directory, $structure, $output);

        // Create files
        $files = [
            'public/index.php' => $this->getIndexPhpContent(),
            'config/app.php' => $this->getAppConfigContent(),
            '.gitignore' => $this->getGitignoreContent(),
        ];

        foreach ($files as $path => $content) {
            $fullPath = $directory . DIRECTORY_SEPARATOR . $path;

            if (file_exists($fullPath) && !$force) {
                $output->writeln(sprintf('  Skipped %s (already exists)', $path));
                continue;
            }

            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            file_put_contents($fullPath, $content);
            $output->writeln(sprintf('  Created %s', $path));
        }

        $output->newLine();
        $output->success('Project initialized successfully!');
        $output->newLine();
        $output->writeln('Next steps:');
        $output->writeln('  1. cd ' . basename($directory));
        $output->writeln('  2. composer install');
        $output->writeln('  3. php -S localhost:8000 -t public');

        return ExitCode::Success->value;
    }

    /**
     * @param array<string, array<string, mixed>> $structure
     */
    private function createStructure(string $base, array $structure, OutputInterface $output): void
    {
        foreach ($structure as $name => $contents) {
            $path = $base . DIRECTORY_SEPARATOR . $name;

            if (!is_dir($path)) {
                mkdir($path, 0o755, true);
                $output->writeln(sprintf('  Created %s/', $name));
            }

            /** @var array<string, array<string, mixed>> $nestedStructure */
            $nestedStructure = $contents;
            if ($nestedStructure !== []) {
                $this->createStructure($path, $nestedStructure, $output);
            }
        }
    }

    private function getIndexPhpContent(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            require __DIR__ . '/../vendor/autoload.php';

            use Pulsar\Core\Kernel;
            use Pulsar\Http\Response;

            $kernel = new Kernel();

            // Register routes
            $kernel->router()->get('/', fn() => Response::html('<h1>Welcome to Pulsar!</h1>'));

            // Run the application
            $kernel->run();
            PHP;
    }

    private function getAppConfigContent(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'name' => 'My Pulsar App',
                'debug' => true,

                'extensions' => [
                    'paths' => [
                        __DIR__ . '/../extensions',
                    ],
                ],
            ];
            PHP;
    }

    private function getGitignoreContent(): string
    {
        return <<<'TEXT'
            /vendor/
            /.idea/
            /.vscode/
            .env
            .env.local
            *.cache
            *.log
            TEXT;
    }
}
