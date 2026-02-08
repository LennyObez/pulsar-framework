<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_dir;
use function is_string;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\EventIngestionTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;
use function trim;

/**
 * Scaffold an event ingestion pipeline with webhook verification and deduplication.
 */
final class MakeEventIngestionCommand extends Command
{
    use ScaffoldTrait;

    private readonly EventIngestionTemplates $templates;

    public function __construct()
    {
        $this->templates = new EventIngestionTemplates();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:event-ingestion';
        $this->description = 'Generate an event ingestion pipeline with webhook verification';
        $this->addArgument('name', 'Ingestion name (e.g., GitHub, Stripe)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('events', 'Comma-separated event types (e.g., push,pull_request)');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');
        $eventsRaw = $input->getOption('events', '');

        if (!is_string($name) || $name === '') {
            $output->errorln('Ingestion name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($module) || $module === '') {
            $output->errorln('Module name is required (--module).');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        $name = $this->toPascalCase($name);
        $module = $this->toPascalCase($module);

        /** @var list<string> $events */
        $events = [];
        if (is_string($eventsRaw) && $eventsRaw !== '') {
            $events = array_values(array_filter(array_map(
                fn(string $e): string => trim($e),
                explode(',', $eventsRaw),
            )));
        }

        $resolved = $this->resolveBasePath($basePath, 'app/Modules');
        if ($resolved === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        $modulePath = $resolved . DIRECTORY_SEPARATOR . $module;

        if (!is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" does not exist at %s', $module, $modulePath));
            return ExitCode::Error->value;
        }

        $namespace = 'App\\Modules\\' . $module;

        $output->writeln(sprintf('Creating event ingestion: %s in %s', $name, $module));
        $output->newLine();

        // Ensure all directories exist
        foreach (['Contracts', 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure', 'Controller', 'Config', 'Domain', 'Exception'] as $dir) {
            $fullDir = $modulePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0o755, true);
                $output->writeln(sprintf('  Created %s/', $dir));
            }
        }

        // Create files
        $this->writeFiles($modulePath, [
            'Contracts' . DIRECTORY_SEPARATOR . $name . 'EventHandlerInterface.php' => $this->templates->eventHandlerInterface($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'EventHandler.php' => $this->templates->eventHandler($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'HmacVerifier.php' => $this->templates->hmacVerifier($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'InMemory' . $name . 'EventLog.php' => $this->templates->eventLog($name, $namespace),
            'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookController.php' => $this->templates->controller($name, $namespace),
            'Config' . DIRECTORY_SEPARATOR . $name . 'IngestionConfig.php' => $this->templates->config($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'Event.php' => $this->templates->event($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'EventType.php' => $this->templates->eventType($name, $namespace, $events),
            'Exception' . DIRECTORY_SEPARATOR . $name . 'IngestionException.php' => $this->templates->exception($name, $namespace),
            $name . 'IngestionServiceProvider.php' => $this->templates->serviceProvider($name, $namespace),
            'README.md' => $this->templates->readme($name),
        ], $output);

        // Create tests
        $cwd = getcwd();
        if ($cwd !== false) {
            $testBase = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module;

            foreach (['Internal' . DIRECTORY_SEPARATOR . 'Infrastructure', 'Controller'] as $dir) {
                $fullDir = $testBase . DIRECTORY_SEPARATOR . $dir;
                if (!is_dir($fullDir)) {
                    mkdir($fullDir, 0o755, true);
                }
            }

            $this->writeFiles($testBase, [
                'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'EventHandlerTest.php' => $this->templates->eventHandlerTest($name, $module, $namespace),
                'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookControllerTest.php' => $this->templates->controllerTest($name, $module, $namespace),
            ], $output);
        }

        $output->newLine();
        $output->success(sprintf('Event ingestion "%s" created in %s/', $name, $module));

        return ExitCode::Success->value;
    }
}
