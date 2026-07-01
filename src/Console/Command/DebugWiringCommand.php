<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Core\Wiring\Contract\WiringContractInspector;
use Pulsar\Core\Wiring\WiringList;

use function count;
use function sprintf;

/**
 * Show service-wiring contracts and any degraded or unsatisfied bindings.
 *
 * Surfaces, against the live container, what each shipped wiring provides,
 * requires and optionally consumes — and which optional features are currently
 * inert because a binding is missing (e.g. anti-spam replay protection when the
 * cache is off). Makes the framework's binding graph and its degraded state
 * discoverable at runtime instead of only via a failing request.
 *
 * Usage: debug:wiring
 */
#[Internal]
final class DebugWiringCommand extends Command
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'debug:wiring';
        $this->description = 'Show service-wiring contracts and any degraded or unsatisfied bindings';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $contracts = $this->describedContracts();

        if ($contracts === []) {
            $output->writeln('No wiring contracts are described.');

            return ExitCode::Success->value;
        }

        foreach ($contracts as $contract) {
            $this->renderContract($output, $contract);
        }

        $inspector = new WiringContractInspector($this->container);
        $degraded = $inspector->degradedFeatures($contracts);
        $unsatisfied = $inspector->unsatisfiedRequirements($contracts);

        if ($degraded !== []) {
            $output->info('Degraded features');

            foreach ($degraded as $feature) {
                $prefix = $feature->security ? '  [security] ' : '  ';
                $output->writeln($prefix . $feature->describe());
            }

            $output->newLine();
        }

        foreach ($unsatisfied as $gap) {
            $output->error(sprintf(
                'Unsatisfied: %s requires %s, which nothing in the graph provides',
                $gap['component'],
                $gap['binding'],
            ));
        }

        return $this->summarize($output, count($degraded), count($unsatisfied));
    }

    /**
     * @return list<WiringContract>
     */
    private function describedContracts(): array
    {
        $contracts = [];

        foreach (WiringList::default() as $wiring) {
            if ($wiring instanceof DescribesWiring) {
                $contracts[] = $wiring->describeWiring();
            }
        }

        return $contracts;
    }

    private function renderContract(OutputInterface $output, WiringContract $contract): void
    {
        $heading = $contract->component;

        if ($contract->configFile !== null) {
            $heading .= sprintf('  (config: %s)', $contract->configFile);
        }

        $output->info($heading);

        $table = new TableFormatter();
        $table->setHeaders(['Kind', 'Binding', 'Bound']);

        foreach ($contract->provides as $binding) {
            $table->addRow(['provides', $binding, $this->boundLabel($binding)]);
        }

        foreach ($contract->requires as $binding) {
            $table->addRow(['requires', $binding, $this->boundLabel($binding)]);
        }

        foreach ($contract->optional as $optional) {
            $table->addRow(['optional', $optional->binding, $this->boundLabel($optional->binding)]);
        }

        $table->render($output);
        $output->newLine();
    }

    private function boundLabel(string $binding): string
    {
        return $this->container->has($binding) ? 'yes' : 'no';
    }

    private function summarize(OutputInterface $output, int $degraded, int $unsatisfied): int
    {
        if ($unsatisfied > 0) {
            $output->error(sprintf('%d unsatisfied requirement(s).', $unsatisfied));

            return ExitCode::Error->value;
        }

        if ($degraded > 0) {
            $output->warning(sprintf('%d degraded feature(s) — see above for the fix.', $degraded));

            return ExitCode::Success->value;
        }

        $output->success('All wiring contracts are satisfied.');

        return ExitCode::Success->value;
    }
}
