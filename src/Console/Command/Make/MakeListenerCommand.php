<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function is_int;
use function is_string;
use function mkdir;
use function sprintf;

/**
 * Scaffold a listener class for an event.
 */
final class MakeListenerCommand extends Command
{
    use ScaffoldTrait;

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:listener';
        $this->description = 'Generate a listener class for an event';
        $this->addArgument('name', 'Listener name (e.g., SendWelcomeEmail)', true);
        $this->addOption('event', 'Event class to listen for', 'e');
        $this->addOption('module', 'Target module', 'm');
        $this->addOption('path', 'Base modules path', 'p', 'app/Modules');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->resolveModuleContext($input, $output, 'Listener');

        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath, $namespace] = $context;

        $eventClass = $input->getOption('event');

        if (!is_string($eventClass) || $eventClass === '') {
            $output->errorln('Event class is required (--event).');
            return ExitCode::Invalid->value;
        }

        $listenerDir = $modulePath . DIRECTORY_SEPARATOR . 'Listener';

        if (!is_dir($listenerDir)) {
            mkdir($listenerDir, 0o750, true);
        }

        $filePath = $listenerDir . DIRECTORY_SEPARATOR . $name . '.php';

        if (file_exists($filePath)) {
            $output->errorln(sprintf('Listener "%s" already exists at %s', $name, $filePath));
            return ExitCode::Error->value;
        }

        $content = $this->generateListenerContent($namespace, $name, $eventClass);
        file_put_contents($filePath, $content);

        $output->success(sprintf('Listener created: %s', $filePath));

        return ExitCode::Success->value;
    }

    private function generateListenerContent(string $namespace, string $name, string $eventClass): string
    {
        // Extract just the short class name for the parameter
        $parts = explode('\\', $eventClass);
        $shortEventName = end($parts);
        $eventVar = lcfirst($shortEventName);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Listener;

            use {$eventClass};
            use Psr\\Log\\LoggerInterface;

            /**
             * Handles {$shortEventName} events.
             */
            final readonly class {$name}
            {
                public function __construct(
                    private LoggerInterface \$logger,
                ) {}

                public function __invoke({$shortEventName} \${$eventVar}): void
                {
                    \$this->logger->info('{$name} handling {$shortEventName}', [
                        'id' => \${$eventVar}->id,
                    ]);
                }
            }
            PHP;
    }
}
