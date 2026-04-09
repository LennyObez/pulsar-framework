<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\EventIngestionTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function is_dir;
use function is_int;
use function sprintf;

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
        $context = $this->resolveModuleContext($input, $output, 'Ingestion');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath, $namespace] = $context;

        $events = $this->parseCommaSeparatedOption($input->getStringOption('events'));

        $output->writeln(sprintf('Creating event ingestion: %s in %s', $name, $module));
        $output->newLine();

        // Ensure all directories exist
        foreach (['Contracts', 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure', 'Controller', 'Config', 'Domain', 'Exception'] as $dir) {
            $fullDir = $modulePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0o750, true);
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
        $testBase = $this->resolveTestBasePath($module, [
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure',
            'Controller',
        ]);
        if ($testBase !== false) {
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
