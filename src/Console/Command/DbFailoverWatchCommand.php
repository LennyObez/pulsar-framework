<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Closure;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\Failover\FailoverManagerInterface;
use Pulsar\Database\Failover\FailoverStateStore;
use Pulsar\Support\Coerce;

use function function_exists;
use function max;
use function sleep;
use function sprintf;

use const SIGINT;
use const SIGTERM;

/**
 * Long-running watcher that detects primary-database failure out of band and
 * publishes the promoted endpoint to the shared {@see FailoverStateStore}.
 *
 * This is the consumer that activates the {@see FailoverManagerInterface}:
 * short-lived web/worker processes never health-check the primary themselves —
 * they read the shared store — so detection runs here, in one supervised
 * process, on a fixed interval. Each tick health-checks the primary (feeding
 * the manager's circuit breaker); when the breaker opens the manager fails over
 * and the new endpoint is recorded for every other process to pick up.
 *
 * The manager is built lazily (it opens a database connection) so constructing
 * the command — which the CLI does for every invocation — costs nothing until
 * the watcher actually runs. Deploy it like a queue worker (systemd/supervisor);
 * `--max-ticks` bounds the run for cron-style scheduling or tests.
 */
final class DbFailoverWatchCommand extends Command
{
    private bool $shouldStop = false;

    /**
     * @param Closure(): FailoverManagerInterface $managerFactory
     */
    public function __construct(
        private readonly Closure $managerFactory,
        private readonly FailoverStateStore $store,
        private readonly int $intervalSeconds = 5,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'db:failover:watch';
        $this->description = 'Watch the primary database and fail over to a standby when it becomes unreachable';

        $this->addOption('interval', 'Seconds between primary health checks', null, (string) $this->intervalSeconds);
        $this->addOption('max-ticks', 'Stop after N checks (0 = run until signalled)', null, '0');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int) $this->stringOption($input, 'interval', (string) $this->intervalSeconds));
        $maxTicks = max(0, (int) $this->stringOption($input, 'max-ticks', '0'));

        $manager = ($this->managerFactory)();

        $output->info(sprintf(
            'Database failover watcher started (interval: %ds, max-ticks: %s, current primary: %s).',
            $interval,
            $maxTicks === 0 ? 'unlimited' : (string) $maxTicks,
            $manager->getCurrentPrimary(),
        ));

        $this->installSignalHandlers();

        $ticks = 0;

        while (!$this->shouldStop) {
            if ($this->runTick($manager)) {
                $output->errorln(sprintf(
                    'Failover executed: primary switched to %s.',
                    $manager->getCurrentPrimary(),
                ));
            }

            $ticks++;

            if ($maxTicks !== 0 && $ticks >= $maxTicks) {
                break;
            }

            sleep($interval);
        }

        $output->success('Database failover watcher stopped.');

        return ExitCode::Success->value;
    }

    /**
     * Evaluate the primary once. Returns true when a failover was executed.
     *
     * The manager records the health check into its circuit breaker; failover
     * is executed only once the breaker has opened (failure threshold reached),
     * and the promoted endpoint is published to the shared store.
     */
    private function runTick(FailoverManagerInterface $manager): bool
    {
        $healthy = $manager->checkPrimary();

        if ($healthy || !$manager->isCircuitOpen()) {
            return false;
        }

        if (!$manager->executeFailover()) {
            return false;
        }

        $endpoint = $manager->getCurrentPrimary();
        $this->store->recordFailover($endpoint);

        $this->logger?->warning('Database failover executed; primary switched.', ['endpoint' => $endpoint]);

        return true;
    }

    private function stringOption(InputInterface $input, string $name, string $default): string
    {
        return Coerce::stringFromInput($input->getOption($name), $default);
    }

    /**
     * Register POSIX signal handlers for graceful shutdown on Unix. No-op where
     * ext-pcntl is unavailable (e.g. Windows); the run then ends on --max-ticks
     * or when the supervisor terminates the process.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->shouldStop = true;
        };

        /** @psalm-suppress UndefinedConstant SIGINT/SIGTERM are POSIX-only, guarded above. */
        $sigint = (int) SIGINT;
        /** @psalm-suppress UndefinedConstant */
        $sigterm = (int) SIGTERM;

        pcntl_signal($sigint, $handler);
        pcntl_signal($sigterm, $handler);
    }
}
